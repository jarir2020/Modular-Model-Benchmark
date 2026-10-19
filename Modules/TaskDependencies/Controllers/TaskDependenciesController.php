<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Task Dependencies module, roadmap #9.

namespace Modules\TaskDependencies\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Projects\Models\Project;
use Modules\TaskDependencies\Models\TaskDependency;
use Modules\Tasks\Models\Task;

class TaskDependenciesController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'project_id' => $this->queryScalar($request, 'project_id'),
        ];

        $dependencies = TaskDependency::query()
            ->with([
                'blockingTask:id,title,project_id',
                'blockingTask.project:id,name',
                'blockedTask:id,title,project_id',
                'blockedTask.project:id,name',
            ])
            ->forProject($filters['project_id'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TaskDependency $dependency): array => $this->dependencyPayload($dependency))
            ->values()
            ->all();

        // A dependency links two tasks inside one project (enforced on write),
        // so the task pickers on the form scope to the selected project.
        $tasks = Task::query()
            ->with('project:id,name')
            ->forProject($filters['project_id'])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
            ])
            ->values()
            ->all();

        return view('task-dependencies::index', [
            'pageProps' => [
                'initialDependencies' => $dependencies,
                'filters' => $filters,
                'projects' => Project::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Project $project): array => [
                        'id' => $project->id,
                        'name' => $project->name,
                    ])
                    ->values()
                    ->all(),
                'tasks' => $tasks,
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('task-dependencies.index'),
                    'store' => route('task-dependencies.store'),
                    'destroy' => route('task-dependencies.destroy', ['taskDependency' => '__DEPENDENCY_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedDependency($request);

        $blocking = Task::find($validated['blocking_task_id']);
        $blocked = Task::find($validated['blocked_task_id']);

        if ($blocking === null || $blocked === null) {
            // Unreachable while the exists rules pass, but it keeps the
            // dereferences below provably null-safe.
            throw ValidationException::withMessages([
                'blocking_task_id' => 'Both linked tasks must exist.',
            ]);
        }

        // Invariant: a dependency orders work inside one project, so both
        // tasks must belong to the same project.
        if ($blocking->project_id !== $blocked->project_id) {
            throw ValidationException::withMessages([
                'blocked_task_id' => 'A dependency can only link two tasks from the same project.',
            ]);
        }

        // Invariant: "blocks" must keep the graph acyclic, or no task on a
        // cycle could ever start. Adding edge B → C (B blocks C) closes a
        // cycle exactly when C already reaches B by following the arrows —
        // walking forward from the blocked task, through the tasks it blocks,
        // answers that directly. The reverse edge (C blocks B while B already
        // blocks C) is the two-node case this walk catches first.
        $adjacency = $this->blockedAdjacency();
        $target = (int) $blocking->id;
        $visited = [];
        $queue = [(int) $blocked->id];

        while ($queue !== []) {
            $taskId = array_shift($queue);

            if ($taskId === $target) {
                throw ValidationException::withMessages([
                    'blocked_task_id' => 'That link would create a circular dependency between the two tasks.',
                ]);
            }

            if (isset($visited[$taskId])) {
                continue;
            }

            $visited[$taskId] = true;

            foreach ($adjacency[$taskId] ?? [] as $blockeeId) {
                $queue[] = $blockeeId;
            }
        }

        try {
            $dependency = TaskDependency::query()->create($validated);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent stores can pass the unique rule before either
            // commits; the schema's pair-unique index is the real backstop.
            throw ValidationException::withMessages([
                'blocked_task_id' => 'That dependency is already recorded.',
            ]);
        }

        $dependency->load([
            'blockingTask:id,title,project_id',
            'blockingTask.project:id,name',
            'blockedTask:id,title,project_id',
            'blockedTask.project:id,name',
        ]);

        return response()->json([
            'dependency' => $this->dependencyPayload($dependency),
        ], 201);
    }

    /**
     * A link is a single fact — two tasks and a direction — so edits mean
     * delete and re-create; only create, list and delete fit the entity.
     * Deleting a task also removes its edges by cascade (see the migration).
     */
    public function destroy(TaskDependency $taskDependency): JsonResponse
    {
        $taskDependency->delete();

        return response()->json(null, 204);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?project_id[]=a&project_id[]=b) must not reach the where
     * clauses. Mirrors TaskController::queryScalar().
     */
    private function queryScalar(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /**
     * All dependency edges as blocking-task-id => blocked-task-ids. Loaded
     * once per store so the cycle walk costs two queries instead of one per
     * hop. At this app's scale (a single SQLite file) the whole graph fits in
     * memory; a graph store would want a recursive CTE or closure table here.
     *
     * @return array<int, list<int>>
     */
    private function blockedAdjacency(): array
    {
        $adjacency = [];

        foreach (TaskDependency::query()->get(['blocking_task_id', 'blocked_task_id']) as $edge) {
            $adjacency[(int) $edge->blocking_task_id][] = (int) $edge->blocked_task_id;
        }

        return $adjacency;
    }

    /**
     * @return array{blocking_task_id: int, blocked_task_id: int}
     */
    private function validatedDependency(Request $request): array
    {
        return $request->validate([
            'blocking_task_id' => ['required', 'integer', Rule::exists('tasks', 'id')],
            'blocked_task_id' => [
                'required',
                'integer',
                Rule::exists('tasks', 'id'),
                // Invariant 1 (self-link): a task can never block itself.
                'different:blocking_task_id',
                // Invariant 2 (duplicate link): the same directed pair exists
                // at most once, matching the schema's pair-unique index.
                Rule::unique('task_dependencies', 'blocked_task_id')
                    ->where('blocking_task_id', $request->input('blocking_task_id')),
            ],
        ], [
            'blocked_task_id.different' => 'A task cannot block itself.',
            'blocked_task_id.unique' => 'That dependency is already recorded.',
        ]);
    }

    /**
     * @return array{id: int, blocking_task_id: int, blocking_task_title: ?string, blocking_task_project_name: ?string, blocked_task_id: int, blocked_task_title: ?string, blocked_task_project_name: ?string, created_at: ?string}
     */
    private function dependencyPayload(TaskDependency $dependency): array
    {
        return [
            'id' => $dependency->id,
            'blocking_task_id' => $dependency->blocking_task_id,
            'blocking_task_title' => $dependency->blockingTask?->title,
            'blocking_task_project_name' => $dependency->blockingTask?->project?->name,
            'blocked_task_id' => $dependency->blocked_task_id,
            'blocked_task_title' => $dependency->blockedTask?->title,
            'blocked_task_project_name' => $dependency->blockedTask?->project?->name,
            'created_at' => $dependency->created_at?->toIso8601String(),
        ];
    }
}
