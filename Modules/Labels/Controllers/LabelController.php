<?php

// Implemented with model: zm/atria-asi/atria-dawn-preview (Atria Dawn Preview,
// running as Claude Code) — Labels module, roadmap #4.

namespace Modules\Labels\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Labels\Models\Label;
use Modules\Tasks\Models\Task;

class LabelController extends Controller
{
    public function index(): View
    {
        $labels = Label::query()
            ->withCount('tasks')
            ->orderBy('name')
            ->get();

        $tasks = Task::query()
            ->with('labels:id,name,color')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->get();

        return view('labels::index', [
            'pageProps' => [
                'initialLabels' => $labels->map(fn (Label $label): array => $this->labelPayload($label))->values()->all(),
                'initialTasks' => $tasks->map(fn (Task $task): array => $this->taskPayload($task))->values()->all(),
                'csrfToken' => csrf_token(),
                'routes' => $this->routes(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $label = Label::query()->create($this->validatedLabel($request, null));

        return response()->json([
            'label' => $this->labelPayload($label),
        ], 201);
    }

    public function update(Request $request, Label $label): JsonResponse
    {
        $label->update($this->validatedLabel($request, $label));
        $label->loadCount('tasks');

        return response()->json([
            'label' => $this->labelPayload($label),
        ]);
    }

    public function destroy(Label $label): JsonResponse
    {
        // The pivot rows are removed by the on-delete cascade; detach is
        // explicit anyway so the model stays correct under other database
        // drivers that do not enforce foreign keys by default.
        $label->tasks()->detach();
        $label->delete();

        return response()->json(null, 204);
    }

    /**
     * Attach labels to a task. Labels already attached are rejected rather
     * than erroring on the pivot's unique constraint.
     */
    public function attach(Request $request, Task $task): JsonResponse
    {
        $existing = $task->labels()->pluck('labels.id')->all();

        $validated = $request->validate([
            'label_ids' => ['required', 'array', 'min:1'],
            'label_ids.*' => [
                'integer',
                Rule::exists('labels', 'id'),
                // Reject duplicates of an already-attached label instead of
                // erroring on the unique pivot constraint.
                Rule::notIn($existing),
            ],
        ]);

        $task->labels()->attach($validated['label_ids']);

        return response()->json([
            'task' => $this->taskPayload($task->refresh()),
        ]);
    }

    /**
     * Remove specific labels from a task.
     */
    public function detach(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'label_ids' => ['required', 'array', 'min:1'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')],
        ]);

        $task->labels()->detach($validated['label_ids']);

        return response()->json([
            'task' => $this->taskPayload($task->refresh()),
        ]);
    }

    /**
     * Replace the full set of labels on a task in one write. An empty list is
     * meaningful here — it clears the task's labels — so `present` is used
     * instead of `required`, which would reject an empty array.
     */
    public function sync(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'label_ids' => ['present', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')],
        ]);

        $task->labels()->sync($validated['label_ids']);

        return response()->json([
            'task' => $this->taskPayload($task->refresh()),
        ]);
    }

    /**
     * @return array{index: string, store: string, update: string, destroy: string, attach: string, detach: string, sync: string}
     */
    private function routes(): array
    {
        return [
            'index' => route('labels.index'),
            'store' => route('labels.store'),
            'update' => route('labels.update', ['label' => '__LABEL_ID__']),
            'destroy' => route('labels.destroy', ['label' => '__LABEL_ID__']),
            'attach' => route('labels.attach', ['task' => '__TASK_ID__']),
            'detach' => route('labels.detach', ['task' => '__TASK_ID__']),
            'sync' => route('labels.sync', ['task' => '__TASK_ID__']),
        ];
    }

    /**
     * @return array{name: string, color: string, description: ?string}
     */
    private function validatedLabel(Request $request, ?Label $label): array
    {
        $nameRules = ['required', 'string', 'max:120'];

        if ($label !== null) {
            // Ignore this label's own row when enforcing the unique name.
            $nameRules[] = Rule::unique('labels', 'name')->ignore($label->id);
        } else {
            $nameRules[] = Rule::unique('labels', 'name');
        }

        return $request->validate([
            'name' => $nameRules,
            'color' => ['nullable', 'string', 'max:32', 'regex:'.Label::COLOR_PATTERN],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array{id: int, name: string, color: string, description: ?string, task_count: int, created_at: ?string}
     */
    private function labelPayload(Label $label): array
    {
        return [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
            'description' => $label->description,
            // Present when the index eager-loads the count; absent on single-row
            // responses, where the client already has it.
            'task_count' => $label->tasks_count !== null ? (int) $label->tasks_count : 0,
            'created_at' => $label->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, title: string, labels: array<int, array{id: int, name: string, color: string}>}
     */
    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'labels' => $task->labels
                ->sortBy('name')
                ->map(fn (Label $label): array => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                ])
                ->values()
                ->all(),
        ];
    }
}
