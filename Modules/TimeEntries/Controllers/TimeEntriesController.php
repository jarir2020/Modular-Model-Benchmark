<?php

namespace Modules\TimeEntries\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Tasks\Models\Task;
use Modules\TimeEntries\Models\TimeEntry;

class TimeEntriesController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'task_id' => $this->queryScalar($request, 'task_id'),
            'user_id' => $this->queryScalar($request, 'user_id'),
            'billable' => $this->queryScalar($request, 'billable'),
        ];

        $entries = TimeEntry::query()
            ->with(['task:id,title', 'user:id,name'])
            ->forTask($filters['task_id'])
            ->forUser($filters['user_id'])
            ->billable($filters['billable'])
            ->orderByDesc('spent_on')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TimeEntry $entry): array => $this->entryPayload($entry))
            ->values()
            ->all();

        return view('time-entries::index', [
            'pageProps' => [
                'initialEntries' => $entries,
                'filters' => $filters,
                // Aggregated totals for the current filter set, matching
                // what the list below shows.
                'totals' => [
                    'total_minutes' => array_sum(array_column($entries, 'duration_minutes')),
                    'billable_minutes' => array_sum(array_column(
                        array_filter($entries, fn (array $entry): bool => (bool) $entry['billable']),
                        'duration_minutes',
                    )),
                    'count' => count($entries),
                ],
                'tasks' => Task::query()
                    ->with('project:id,name')
                    ->orderBy('title')
                    ->get()
                    ->map(fn (Task $task): array => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'project_id' => $task->project_id,
                        'project_name' => $task->project?->name,
                    ])
                    ->values()
                    ->all(),
                'users' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])
                    ->values()
                    ->all(),
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('time-entries.index'),
                    'store' => route('time-entries.store'),
                    'update' => route('time-entries.update', ['timeEntry' => '__ENTRY_ID__']),
                    'destroy' => route('time-entries.destroy', ['timeEntry' => '__ENTRY_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedEntry($request);

        $entry = TimeEntry::query()->create($data);
        $entry->load(['task:id,title', 'user:id,name']);

        return response()->json([
            'entry' => $this->entryPayload($entry),
        ], 201);
    }

    public function update(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $data = $this->validatedEntry($request);
        $timeEntry->update($data);

        return response()->json([
            'entry' => $this->entryPayload($timeEntry->refresh()->load(['task:id,title', 'user:id,name'])),
        ]);
    }

    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        $timeEntry->delete();

        return response()->json(null, 204);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?task_id[]=a&task_id[]=b) must not reach the where clauses.
     * Mirrors TaskController::queryScalar().
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
     * @return array{task_id: int, user_id: int, duration_minutes: int, spent_on: string, note: ?string, billable: bool}
     */
    private function validatedEntry(Request $request): array
    {
        return $request->validate([
            'task_id' => ['required', 'integer', Rule::exists('tasks', 'id')],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            // Duration is whole minutes, at least one — fractions of a minute
            // and zero-length entries have no meaning on a timesheet.
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'billable' => ['required', 'boolean'],
        ], [
            'duration_minutes.min' => 'A time entry must be at least one minute.',
            'duration_minutes.max' => 'A single entry cannot exceed 24 hours (1440 minutes).',
            'spent_on.before_or_equal' => 'The entry date cannot be in the future.',
        ]);
    }

    /**
     * @return array{id: int, task_id: int, task_title: ?string, user_id: int, user_name: ?string, duration_minutes: int, spent_on: ?string, note: ?string, billable: bool, created_at: ?string}
     */
    private function entryPayload(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'task_id' => $entry->task_id,
            'task_title' => $entry->task?->title,
            'user_id' => $entry->user_id,
            'user_name' => $entry->user?->name,
            'duration_minutes' => (int) $entry->duration_minutes,
            'spent_on' => $entry->spent_on?->toDateString(),
            'note' => $entry->note,
            'billable' => (bool) $entry->billable,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
