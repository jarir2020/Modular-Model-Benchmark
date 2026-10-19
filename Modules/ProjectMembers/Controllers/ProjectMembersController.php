<?php

namespace Modules\ProjectMembers\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\ProjectMembers\Models\ProjectMember;
use Modules\Projects\Models\Project;
use Modules\TeamMembers\Models\TeamMember;
use Modules\Teams\Models\Team;

class ProjectMembersController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actingUser();

        $filters = [
            'project_id' => $this->queryScalar($request, 'project_id'),
            'access_level' => $this->queryScalar($request, 'access_level'),
            'q' => $this->queryScalar($request, 'q'),
        ];

        // Read scope: the rosters of projects the acting user belongs to, plus
        // projects nobody has joined yet — their roster is empty, but the page
        // has to offer them so the first member can be claimed. Guessing the id
        // of a project the user is not on returns an empty page rather than
        // that project's membership.
        $visibleProjectIds = $this->visibleProjectIds($actor);

        $members = ProjectMember::query()
            ->with(['project:id,name', 'user:id,name,email'])
            ->whereIn('project_id', $visibleProjectIds)
            ->forProject($filters['project_id'])
            ->withAccessLevel($filters['access_level'])
            ->search($filters['q'])
            ->orderBy('project_id')
            ->orderBy('id')
            ->get()
            ->map(fn (ProjectMember $member): array => $this->memberPayload($member))
            ->values()
            ->all();

        // One snapshot of every visible roster, used to work out each project's
        // size and whether the acting user administers it.
        $rowsByProject = ProjectMember::query()
            ->whereIn('project_id', $visibleProjectIds)
            ->get(['project_id', 'user_id', 'access_level'])
            ->groupBy(fn (ProjectMember $row): int => (int) $row->project_id);

        $projects = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Project $project) use ($rowsByProject, $actor): array {
                $rows = $rowsByProject->get((int) $project->getKey(), collect());
                $isAdmin = $actor !== null && $this->holdsAdmin($rows, $actor);

                return [
                    'id' => (int) $project->getKey(),
                    'name' => $project->name,
                    'member_count' => $rows->count(),
                    // A project with no members yet is open to the claim below;
                    // once it has members, only its admins manage it.
                    'is_unclaimed' => $rows->isEmpty(),
                    'is_admin' => $isAdmin,
                    'can_manage' => $isAdmin || $rows->isEmpty(),
                ];
            })
            ->values()
            ->all();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->values();

        // The shared-team rule needs every user's team context at once; two
        // batched queries beat one pair per listed user.
        $teamIdsByUser = $actor === null
            ? collect()
            : $this->teamIdsForMany(
                $users->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->push((int) $actor->getKey())
                    ->unique(),
            );
        $actorTeamIds = $actor === null
            ? collect()
            : $teamIdsByUser->get((int) $actor->getKey(), collect());

        return view('project-members::index', [
            'pageProps' => [
                'initialMembers' => $members,
                'filters' => $filters,
                'projects' => $projects,
                // `eligible` marks who the acting user may pull onto a project:
                // themselves, plus anyone they share an active team with.
                'users' => $users->map(function (User $user) use ($actor, $actorTeamIds, $teamIdsByUser): array {
                    $userId = (int) $user->getKey();
                    $isSelf = $actor !== null && (int) $actor->getKey() === $userId;
                    $sharesTeam = $actorTeamIds->intersect(
                        $teamIdsByUser->get($userId, collect()),
                    )->isNotEmpty();

                    return [
                        'id' => $userId,
                        'name' => $user->name,
                        'email' => $user->email,
                        'eligible' => $isSelf || $sharesTeam,
                    ];
                })->values()->all(),
                'accessLevels' => ProjectMember::ACCESS_LEVELS,
                'actingUserId' => $actor === null ? null : (int) $actor->getKey(),
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('project-members.index'),
                    'store' => route('project-members.store'),
                    'update' => route('project-members.update', ['projectMember' => '__MEMBER_ID__']),
                    'destroy' => route('project-members.destroy', ['projectMember' => '__MEMBER_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actingUser();

        abort_if($actor === null, 403, 'A signed-in user is required to add project members.');

        $data = $this->validatedMembership($request);

        $project = Project::query()->findOrFail($data['project_id']);
        $rows = $this->projectRows($project);

        // A project with members has an admin to ask; one that nobody has
        // joined yet has no authority to inherit, so any signed-in user may
        // claim it — but only for themself (checked below).
        if ($rows->isNotEmpty() && ! $this->holdsAdmin($rows, $actor)) {
            abort(403, 'Only a project admin may change this project\'s membership.');
        }

        // Keep the invariant "a project with members always has an admin", so
        // the roster can never reach a state nobody is allowed to manage.
        if ($rows->isEmpty() && $data['access_level'] !== 'admin') {
            throw ValidationException::withMessages([
                'access_level' => 'The first member of a project must be an admin.',
            ]);
        }

        if ($rows->isEmpty() && (int) $data['user_id'] !== (int) $actor->getKey()) {
            throw ValidationException::withMessages([
                'user_id' => 'A project with no members can only be claimed for yourself.',
            ]);
        }

        if ($rows->contains(fn (ProjectMember $row): bool => (int) $row->user_id === (int) $data['user_id'])) {
            throw ValidationException::withMessages([
                'user_id' => 'That user is already a member of this project.',
            ]);
        }

        // Pulling other people onto a project requires an actual working
        // relationship: an active team (owned or joined) shared with them.
        if (! $this->isSelf($actor, (int) $data['user_id']) && ! $this->sharesActiveTeam($actor, (int) $data['user_id'])) {
            throw ValidationException::withMessages([
                'user_id' => 'You can only add users who share a team with you.',
            ]);
        }

        try {
            $member = ProjectMember::query()->create($data);
        } catch (QueryException $exception) {
            // The unique (project_id, user_id) index is the hard backstop; a
            // concurrent insert that slipped past the check above is still a
            // duplicate membership, not a server error.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'user_id' => 'That user is already a member of this project.',
            ]);
        }

        $member->load(['project:id,name', 'user:id,name,email']);

        return response()->json([
            'member' => $this->memberPayload($member),
        ], 201);
    }

    /**
     * The access level is the only mutable field: the (project, user) pair is
     * the row's identity, so changing either half would be a different
     * membership and belongs to remove + add, not to an update.
     */
    public function update(Request $request, ProjectMember $projectMember): JsonResponse
    {
        $actor = $this->actingUser();
        abort_if($actor === null, 403, 'A signed-in user is required to change access levels.');

        $projectMember->loadMissing('project:id,name');
        $rows = $this->projectRows($projectMember->project);

        abort_unless(
            $this->holdsAdmin($rows, $actor),
            403,
            'Only a project admin may change access levels.',
        );

        $data = $request->validate([
            'access_level' => ['required', 'string', Rule::in(ProjectMember::ACCESS_LEVELS)],
        ], [
            'access_level.in' => 'Choose one of: '.implode(', ', ProjectMember::ACCESS_LEVELS).'.',
        ]);

        // Demoting the only admin would leave members and no administrator, so
        // the roster could never be managed again.
        if ($projectMember->isAdmin()
            && $data['access_level'] !== 'admin'
            && $this->adminCount($rows) === 1) {
            throw ValidationException::withMessages([
                'access_level' => 'A project must keep at least one admin. Promote another member first.',
            ]);
        }

        $projectMember->update(['access_level' => $data['access_level']]);

        $projectMember->refresh()->load(['project:id,name', 'user:id,name,email']);

        return response()->json([
            'member' => $this->memberPayload($projectMember),
        ]);
    }

    public function destroy(ProjectMember $projectMember): JsonResponse
    {
        $actor = $this->actingUser();

        $projectMember->loadMissing('project:id,name');
        $rows = $this->projectRows($projectMember->project);

        $isSelf = $actor !== null && (int) $projectMember->user_id === (int) $actor->getKey();

        // A project admin may remove anyone; any member may remove themself.
        abort_unless(
            $isSelf || $this->holdsAdmin($rows, $actor),
            403,
            'You may only remove yourself, or members of a project you administer.',
        );

        // The last admin may only leave a project that becomes empty: with
        // members left and no admin, nobody could ever manage it again. An
        // emptied project returns to the claimable state above.
        if ($projectMember->isAdmin()
            && $this->adminCount($rows) === 1
            && $rows->count() > 1) {
            throw ValidationException::withMessages([
                'access_level' => 'A project must keep at least one admin. Promote another member first.',
            ]);
        }

        $projectMember->delete();

        return response()->json(null, 204);
    }

    /**
     * The user this module authorizes against, or null when the workspace has
     * no users yet.
     *
     * The host has no login routes yet (the plan defers session auth to a later
     * revision), so resolve the acting user from the session when one is signed
     * in and otherwise fall back to the earliest user as a stand-in. This keeps
     * the authorization boundary real — every read is scoped and every write is
     * checked — instead of skipping authorization until authentication lands.
     * Mirrors TeamMembersController::actingUser().
     */
    private function actingUser(): ?User
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return $user;
        }

        return User::query()->orderBy('id')->first();
    }

    /**
     * The projects whose rosters the acting user may read: the ones they hold a
     * membership row for (whatever its level), plus the ones nobody has joined
     * yet, whose empty roster the page offers for claiming.
     *
     * @return Collection<int, int>
     */
    private function visibleProjectIds(?User $actor): Collection
    {
        if ($actor === null) {
            return collect();
        }

        $claimed = ProjectMember::query()
            ->where('user_id', $actor->getKey())
            ->distinct()
            ->pluck('project_id');

        $unclaimed = Project::query()
            ->whereNotIn('id', ProjectMember::query()->select('project_id'))
            ->pluck('id');

        return $claimed
            ->merge($unclaimed)
            ->unique()
            ->values();
    }

    /**
     * The full roster of one project, fetched once per request so the
     * authorization checks and the last-admin guard all read the same snapshot.
     *
     * @return Collection<int, ProjectMember>
     */
    private function projectRows(Project $project): Collection
    {
        return ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->get(['id', 'project_id', 'user_id', 'access_level']);
    }

    /**
     * Whether the user holds an `admin` row in the given roster.
     */
    private function holdsAdmin(Collection $rows, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $rows->contains(fn (ProjectMember $row): bool => $row->isAdmin()
            && (int) $row->user_id === (int) $user->getKey());
    }

    /**
     * @param  Collection<int, ProjectMember>  $rows
     */
    private function adminCount(Collection $rows): int
    {
        return $rows->filter(fn (ProjectMember $row): bool => $row->isAdmin())->count();
    }

    /**
     * Whether the target membership row is the actor's own: adding yourself
     * needs no shared team, since the authority to claim or join has already
     * been checked above.
     */
    private function isSelf(User $actor, int $userId): bool
    {
        return (int) $actor->getKey() === $userId;
    }

    /**
     * Whether the actor and another user work together: they share a team —
     * one the actor owns or has an active membership on, and one the other user
     * owns or has an active membership on. Teams the other user was merely
     * invited or suspended on do not count.
     */
    private function sharesActiveTeam(User $actor, int $userId): bool
    {
        $teamIdsByUser = $this->teamIdsForMany(collect([$actor->getKey(), $userId]));

        $actorTeamIds = $teamIdsByUser->get($actor->getKey(), collect());
        $otherTeamIds = $teamIdsByUser->get($userId, collect());

        return $actorTeamIds->intersect($otherTeamIds)->isNotEmpty();
    }

    /**
     * The team ids each given user belongs to: the teams they own (an owner is
     * implicitly a member) plus the teams where they hold an active membership
     * row. Batched into two queries so a page of users never fans out per row.
     *
     * @param  Collection<int, int|string>  $userIds
     * @return Collection<int, Collection<int, int>>
     */
    private function teamIdsForMany(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $teamIdsByUser = [];

        foreach (Team::query()->whereIn('owner_id', $userIds)->get(['id', 'owner_id']) as $team) {
            $teamIdsByUser[(int) $team->owner_id][] = (int) $team->getKey();
        }

        $memberships = TeamMember::query()
            ->whereIn('user_id', $userIds)
            ->where('status', 'active')
            ->get(['user_id', 'team_id']);

        foreach ($memberships as $membership) {
            $teamIdsByUser[(int) $membership->user_id][] = (int) $membership->team_id;
        }

        return collect($teamIdsByUser)
            ->map(fn (array $ids): Collection => collect($ids)->unique()->values());
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // SQLSTATE 23000 (SQLite, MySQL) / 23505 (PostgreSQL) are the integrity
        // violations a duplicate pivot row produces.
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?access_level[]=a&access_level[]=b) must not reach the query
     * builder. Mirrors TeamMembersController::queryScalar().
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
     * @return array{project_id: int, user_id: int, access_level: string}
     */
    private function validatedMembership(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'access_level' => ['required', 'string', Rule::in(ProjectMember::ACCESS_LEVELS)],
        ], [
            'project_id.exists' => 'That project no longer exists.',
            'user_id.exists' => 'That user no longer exists.',
            'access_level.in' => 'Choose one of: '.implode(', ', ProjectMember::ACCESS_LEVELS).'.',
        ]);
    }

    /**
     * @return array{id: int, project_id: int, project_name: ?string, user_id: int, user_name: ?string, user_email: ?string, access_level: string, created_at: ?string}
     */
    private function memberPayload(ProjectMember $member): array
    {
        return [
            'id' => (int) $member->getKey(),
            'project_id' => (int) $member->project_id,
            'project_name' => $member->project?->name,
            'user_id' => (int) $member->user_id,
            'user_name' => $member->user?->name,
            'user_email' => $member->user?->email,
            'access_level' => $member->access_level,
            'created_at' => $member->created_at?->toIso8601String(),
        ];
    }
}
