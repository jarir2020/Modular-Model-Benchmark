<?php

/* Implemented by Solar Pro 3 (Claude Code) */

namespace Modules\Milestones\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Milestones\Models\Milestone;
use Modules\Projects\Models\Project;

class MilestonesController extends Controller
{
    public function index(Request $request):  View
    {
        // Get project filter
        $projectId = (int) $request->query('project_id');
        // Scope query to the project
        $milestones = Milestone::query();
        if ($projectId > 0) {
            $milestones->where('project_id', $projectId);
        }
        // User can also filter by status
        $status = $request->query('status');
        if ($status && in_array($status, Milestone::STATUSES)) {
            $milestones->where('status', $status);
        }
        // Due date filter (not needed for now, but can be added)
        $milestones = $milestones->with('project') // eager load project
            ->orderBy('due_date', 'asc')
            ->get();

        // Convert to plain arrays for JSON
        $milestonesArray = $milestones->map(fn (Milestone $m) => [
            'id' => $m->id,
            'project_id' => $m->project_id,
            'name' => $m->name,
            'description' => $m->description,
            'due_date' => $m->due_date?->format('Y-m-d'),
            'status' => $m->status,
            'project_name' => $m->project?->name,
        ])->values()->all();

        return view('milestones::index', [
            'pageProps' => [
                'initialMilestones' => $milestonesArray,
                'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
                'statuses' => Milestone::STATUSES,
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('milestones.index'),
                    'store' => route('milestones.store'),
                    'update' => route('milestones.update', ['milestone' => '__MILESTONE_ID__']),
                    'destroy' => route('milestones.destroy', ['milestone' => '__MILESTONE_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request):  JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(Milestone::STATUSES)],
            'due_date' => ['nullable', 'date'],
        ]);

        // Ensure status matches Milestone enum
        $validated['status'] = $validated['status'] ?? Milestone::STATUSES[0];

        $milestone = Milestone::create($validated);
        // Load project for response (optional)
        $milestone->load('project');

        return response()->json([
            'milestone' => [
                'id' => $milestone->id,
                'project_id' => $milestone->project_id,
                'name' => $milestone->name,
                'description' => $milestone->description,
                'status' => $milestone->status,
                'due_date' => $milestone->due_date?->format('Y-m-d'),
                'project_name' => $milestone->project?->name,
            ],
        ], 201);
    }

    public function update(Request $request, Milestone $milestone):  JsonResponse
    {
        // Validate only the fields that can be updated
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Milestone::STATUSES)],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        if (isset($validated['name'])) {
            $milestone->name = $validated['name'];
        }
        if (isset($validated['description'])) {
            $milestone->description = $validated['description'];
        }
        if (isset($validated['status'])) {
            $milestone->status = $validated['status'];
        }
        if (isset($validated['due_date'])) {
            $milestone->due_date = $validated['due_date'];
        }

        $milestone->save();
        return response()->json([
            'milestone' => [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'description' => $milestone->description,
                'status' => $milestone->status,
                'due_date' => $milestone->due_date?->format('Y-m-d'),
                'project_id' => $milestone->project_id,
                'project_name' => $milestone->project?->name,
            ],
        ]);
    }

    public function destroy(Milestone $milestone):  JsonResponse
    {
        $milestone->delete();
        return response()->json(null, 204);
    }
}
