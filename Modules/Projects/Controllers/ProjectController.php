<?php

namespace Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use Modules\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        $projects = Project::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Project $project): array => $this->projectPayload($project))
            ->values()
            ->all();

        return view('projects::index', [
            'pageProps' => [
                'initialProjects' => $projects,
                'csrfToken' => csrf_token(),
                'routes' => [
                    'store' => route('projects.store'),
                    'update' => route('projects.update', ['project' => '__PROJECT_ID__']),
                    'destroy' => route('projects.destroy', ['project' => '__PROJECT_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = Project::query()->create($this->validatedProject($request));

        return response()->json([
            'project' => $this->projectPayload($project),
        ], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $project->update($this->validatedProject($request));

        return response()->json([
            'project' => $this->projectPayload($project->refresh()),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array{name: string, description: ?string, status: string, due_date: ?string}
     */
    private function validatedProject(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(Project::STATUSES)],
            'due_date' => ['nullable', 'date'],
        ]);
    }

    /**
     * @return array{id: int, name: string, description: ?string, status: string, due_date: ?string, created_at: ?string}
     */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'due_date' => $project->due_date?->format('Y-m-d'),
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }
}
