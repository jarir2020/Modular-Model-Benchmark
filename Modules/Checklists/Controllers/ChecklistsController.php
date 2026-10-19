<?php

// Implemented with model: claude-sonnet-5-5 (Claude Sonnet 5.5) — Checklists module, roadmap #7.

namespace Modules\Checklists\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Checklists\Models\Checklist;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class ChecklistsController extends Controller
{
    public function index(Request $request): View
    {
        $taskId = $this->queryScalar($request, 'task_id');

        if ($taskId === null || $taskId === '') {
            return view('checklists::index', [
                'pageProps' => [
                    'initialChecklists' => [],
                    'taskId' => null,
                    'taskTitle' => null,
                    'csrfToken' => csrf_token(),
                    'routes' => $this->routes(),
                    'projects' => Project::query()
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (Project $p): array => ['id' => $p->id, 'name' => $p->name])
                        ->values()
                        ->all(),
                    'tasks' => [],
                ],
            ]);
        }

        $task = Task::query()->with('project:id,name')->findOrFail($taskId);

        $checklists = Checklist::query()
            ->where('task_id', (int) $taskId)
            ->orderBy('position')
            ->get()
            ->map(fn (Checklist $checklist): array => $this->checklistPayload($checklist))
            ->values()
            ->all();

        return view('checklists::index', [
            'pageProps' => [
                'initialChecklists' => $checklists,
                'taskId' => (int) $taskId,
                'taskTitle' => $task->title,
                'taskProjectName' => $task->project?->name,
                'csrfToken' => csrf_token(),
                'routes' => $this->routes(),
                'projects' => Project::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Project $p): array => ['id' => $p->id, 'name' => $p->name])
                    ->values()
                    ->all(),
                'tasks' => Task::query()
                    ->where('project_id', $task->project_id)
                    ->orderByRaw('due_date is null')
                    ->orderBy('due_date')
                    ->orderByDesc('created_at')
                    ->get(['id', 'title'])
                    ->map(fn (Task $t): array => ['id' => $t->id, 'title' => $t->title])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedChecklist($request);

        $checklist = Checklist::query()->create($data);

        return response()->json([
            'checklist' => $this->checklistPayload($checklist),
        ], 201);
    }

    public function update(Request $request, Checklist $checklist): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
        ]);

        $checklist->update($data);

        return response()->json([
            'checklist' => $this->checklistPayload($checklist),
        ]);
    }

    public function destroy(Checklist $checklist): JsonResponse
    {
        $checklist->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer', Rule::exists('tasks', 'id')],
            'checklist_ids' => ['required', 'array', 'min:1'],
            'checklist_ids.*' => ['integer', Rule::exists('checklists', 'id')],
        ]);

        $taskId = (int) $validated['task_id'];
        $checklistIds = array_map('intval', $validated['checklist_ids']);

        // Reorder only those checklists that belong to the given task.
        $ordered = Checklist::query()
            ->where('task_id', $taskId)
            ->whereIn('id', $checklistIds)
            ->pluck('id')
            ->toArray();

        if (count($ordered) !== count($checklistIds)) {
            return response()->json(['message' => 'Invalid checklist ordering payload.'], 422);
        }

        foreach ($checklistIds as $position => $id) {
            Checklist::query()->where('id', (int) $id)->update(['position' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?task_id[]=a&task_id[]=b) must not reach the where clauses.
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
     * @return array{task_id:int,title:string,position:?int}
     */
    private function validatedChecklist(Request $request): array
    {
        return $request->validate([
            'task_id' => ['required', 'integer', Rule::exists('tasks', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * @return array{ id:int, task_id:int, title:string, position:int }
     */
    private function checklistPayload(Checklist $checklist): array
    {
        return [
            'id' => (int) $checklist->id,
            'task_id' => (int) $checklist->task_id,
            'title' => (string) $checklist->title,
            'position' => (int) $checklist->position,
        ];
    }

    /**
     * @return array{index:string,store:string,update:string,destroy:string,reorder:string}
     */
    private function routes(): array
    {
        return [
            'index' => route('checklists.index'),
            'store' => route('checklists.store'),
            'update' => route('checklists.update', ['checklist' => '__CHECKLIST_ID__']),
            'destroy' => route('checklists.destroy', ['checklist' => '__CHECKLIST_ID__']),
            'reorder' => route('checklists.reorder'),
        ];
    }
}
