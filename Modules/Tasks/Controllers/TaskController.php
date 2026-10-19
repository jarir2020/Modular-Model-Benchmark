<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Tasks module, roadmap #2.

namespace Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'project_id' => $this->queryScalar($request, 'project_id'),
            'status' => $this->queryScalar($request, 'status'),
            'priority' => $this->queryScalar($request, 'priority'),
            'assignee' => $this->queryScalar($request, 'assignee'),
            'search' => trim((string) ($this->queryScalar($request, 'search') ?? '')),
        ];

        $tasks = Task::query()
            ->with(['project:id,name', 'assignee:id,name'])
            ->forProject($filters['project_id'])
            ->withStatus($filters['status'])
            ->withPriority($filters['priority'])
            ->forAssignee($filters['assignee'])
            ->when(
                $filters['search'] !== '',
                fn ($query): \Illuminate\Database\Eloquent\Builder => $query->where(
                    fn ($q): \Illuminate\Database\Eloquent\Builder => $q
                        ->where('title', 'like', '%'.$filters['search'].'%')
                        ->orWhere('description', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->orderByRaw($this->priorityWeightSql())
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Task $task): array => $this->taskPayload($task))
            ->values()
            ->all();

        return view('tasks::index', [
            'pageProps' => [
                'initialTasks' => $tasks,
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
                'assignableUsers' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])
                    ->values()
                    ->all(),
                'statuses' => Task::STATUSES,
                'priorities' => Task::PRIORITIES,
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('tasks.index'),
                    'store' => route('tasks.store'),
                    'update' => route('tasks.update', ['task' => '__TASK_ID__']),
                    'destroy' => route('tasks.destroy', ['task' => '__TASK_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $task = Task::query()->create($this->validatedTask($request));
        $task->load(['project:id,name', 'assignee:id,name']);

        return response()->json([
            'task' => $this->taskPayload($task),
        ], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $task->update($this->validatedTask($request));

        return response()->json([
            'task' => $this->taskPayload($task->refresh()->load(['project:id,name', 'assignee:id,name'])),
        ]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(null, 204);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?status[]=a&status[]=b) must not reach the where clauses.
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
     * CASE expression that maps the priority enum to a sortable weight,
     * used for server-side ordering without a raw dependency on column data.
     */
    private function priorityWeightSql(): string
    {
        $cases = '';

        foreach (Task::PRIORITY_WEIGHTS as $priority => $weight) {
            $cases .= "WHEN '".$priority."' THEN ".$weight.' ';
        }

        return "CASE priority {$cases}ELSE 99 END";
    }

    /**
     * @return array{project_id: int, title: string, description: ?string, status: string, priority: string, due_date: ?string, assignee_id: ?int}
     */
    private function validatedTask(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(Task::STATUSES)],
            'priority' => ['required', 'string', Rule::in(Task::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);
    }

    /**
     * @return array{id: int, project_id: int, project_name: ?string, title: string, description: ?string, status: string, priority: string, due_date: ?string, assignee_id: ?int, assignee_name: ?string, created_at: ?string}
     */
    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'project_name' => $task->project?->name,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'assignee_id' => $task->assignee_id,
            'assignee_name' => $task->assignee?->name,
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }
}
