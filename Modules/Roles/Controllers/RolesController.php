<?php

namespace Modules\Roles\Controllers;

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
use Modules\Roles\Models\Role;
use Modules\TeamMembers\Models\TeamMember;
use Modules\Teams\Models\Team;

class RolesController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actingUser();

        $filters = [
            'team_id' => $this->queryScalar($request, 'team_id'),
            'q' => $this->queryScalar($request, 'q'),
        ];

        // Read scope: the roles of teams the acting user owns, plus the ones
        // of teams they hold a membership row for — nothing else. Guessing
        // another team's id returns an empty page rather than that team's
        // roles.
        $visibleTeamIds = $this->visibleTeamIds($actor);

        $roles = Role::query()
            ->with(['team:id,name,owner_id', 'members.user:id,name,email'])
            ->whereIn('team_id', $visibleTeamIds)
            ->forTeam($filters['team_id'])
            ->search($filters['q'])
            ->orderBy('team_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => $this->rolePayload($role))
            ->values()
            ->all();

        $teams = Team::query()
            ->whereIn('id', $visibleTeamIds)
            ->orderBy('name')
            ->get(['id', 'name', 'owner_id']);

        $ownedTeamIds = $teams
            ->filter(fn (Team $team): bool => $team->isOwnedBy($actor))
            ->pluck('id');

        // Assignment is the owner's call, so only the rosters of the teams the
        // acting user owns are offered as candidates. The memberships that
        // already hold each role arrive embedded in the role payloads above;
        // the page subtracts them from this pool per role.
        $teamMembers = TeamMember::query()
            ->with(['user:id,name,email', 'team:id,name'])
            ->whereIn('team_id', $ownedTeamIds)
            ->orderBy('team_id')
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $member): array => $this->candidatePayload($member))
            ->values()
            ->all();

        return view('roles::index', [
            'pageProps' => [
                'initialRoles' => $roles,
                'filters' => $filters,
                'teams' => $teams
                    ->map(fn (Team $team): array => [
                        'id' => (int) $team->getKey(),
                        'name' => $team->name,
                        'owner_id' => (int) $team->owner_id,
                        // Defining, editing and assigning roles is owner-only,
                        // so the page needs to know which visible teams the
                        // acting user may administer.
                        'is_owned' => $team->isOwnedBy($actor),
                    ])
                    ->values()
                    ->all(),
                'teamMembers' => $teamMembers,
                'actingUserId' => $actor === null ? null : (int) $actor->getKey(),
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('roles.index'),
                    'store' => route('roles.store'),
                    'update' => route('roles.update', ['role' => '__ROLE_ID__']),
                    'destroy' => route('roles.destroy', ['role' => '__ROLE_ID__']),
                    'assign' => route('roles.assign', ['role' => '__ROLE_ID__']),
                    'unassign' => route('roles.unassign', ['role' => '__ROLE_ID__', 'teamMember' => '__MEMBER_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actingUser();

        abort_if($actor === null, 403, 'A signed-in user is required to create roles.');

        $data = $this->validatedRole($request);

        $team = Team::query()->findOrFail($data['team_id']);
        $this->authorizeTeamOwner($team);

        try {
            $role = Role::query()->create($data);
        } catch (QueryException $exception) {
            // The unique (team_id, name) index is the hard backstop; a
            // concurrent insert that slipped past the rule above is still a
            // duplicate role on that team, not a server error.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'name' => 'That team already has a role with that name.',
            ]);
        }

        $role->load(['team:id,name,owner_id', 'members.user:id,name,email']);

        return response()->json([
            'role' => $this->rolePayload($role),
        ], 201);
    }

    /**
     * A role's team is its scope and never changes: renaming or re-describing
     * stays inside that team, and moving a role elsewhere would be a different
     * role (delete + create), not an update. The team_id in the payload is
     * therefore ignored.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $role->loadMissing('team:id,name,owner_id');
        $this->authorizeTeamOwner($role->team);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                // Uniqueness is scoped to the role's own team, and editing a
                // role keeps its own name valid.
                Rule::unique('roles', 'name')
                    ->where('team_id', $role->team_id)
                    ->ignore($role->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.unique' => 'That team already has a role with that name.',
        ]);

        $role->update($data);
        $role->refresh()->load(['team:id,name,owner_id', 'members.user:id,name,email']);

        return response()->json([
            'role' => $this->rolePayload($role),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $role->loadMissing('team:id,name,owner_id');
        $this->authorizeTeamOwner($role->team);

        // Deleting the role removes its assignments through the pivot's
        // cascade, so no member keeps a dangling role reference.
        $role->delete();

        return response()->json(null, 204);
    }

    /**
     * Hand the role to one membership row of the same team. The response
     * returns the whole refreshed role so the page can replace its local copy
     * (assignments included) in one step.
     */
    public function assign(Request $request, Role $role): JsonResponse
    {
        $role->loadMissing('team:id,name,owner_id');
        $this->authorizeTeamOwner($role->team);

        $data = $request->validate([
            'team_member_id' => ['required', 'integer', Rule::exists('team_members', 'id')],
        ], [
            'team_member_id.exists' => 'That team membership no longer exists.',
        ]);

        $member = TeamMember::query()->findOrFail($data['team_member_id']);

        // A role is scoped to one team, so it can only be assigned within
        // that team — a member of any other team is refused outright.
        if ((int) $member->team_id !== (int) $role->team_id) {
            throw ValidationException::withMessages([
                'team_member_id' => 'That member belongs to a different team than the role.',
            ]);
        }

        if ($this->holdsRole($role, $member)) {
            throw ValidationException::withMessages([
                'team_member_id' => 'That member already holds this role.',
            ]);
        }

        try {
            $role->members()->attach($member->getKey());
        } catch (QueryException $exception) {
            // The (role_id, team_member_id) primary key is the hard backstop;
            // a concurrent insert that slipped past the check above is still
            // a duplicate assignment, not a server error.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'team_member_id' => 'That member already holds this role.',
            ]);
        }

        $role->refresh()->load(['team:id,name,owner_id', 'members.user:id,name,email']);

        return response()->json([
            'role' => $this->rolePayload($role),
        ], 201);
    }

    public function unassign(Role $role, TeamMember $teamMember): JsonResponse
    {
        $role->loadMissing('team:id,name,owner_id');
        $this->authorizeTeamOwner($role->team);

        // A member of another team can never hold this role, so an assignment
        // that does not exist — wrong member, or already removed — is a 404
        // rather than a silent success.
        abort_unless(
            $this->holdsRole($role, $teamMember),
            404,
            'That member does not hold this role.',
        );

        $role->members()->detach($teamMember->getKey());

        $role->refresh()->load(['team:id,name,owner_id', 'members.user:id,name,email']);

        return response()->json([
            'role' => $this->rolePayload($role),
        ]);
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
     * The teams whose roles the acting user may read: the ones they own, plus
     * the ones they hold a membership row for (whatever its state, since a row
     * is what makes the membership known to them).
     *
     * @return Collection<int, int>
     */
    private function visibleTeamIds(?User $actor): Collection
    {
        if ($actor === null) {
            return collect();
        }

        $userId = $actor->getKey();

        return Team::query()
            ->where('owner_id', $userId)
            ->pluck('id')
            ->merge(TeamMember::query()->where('user_id', $userId)->pluck('team_id'))
            ->unique()
            ->values();
    }

    /**
     * Role definition and assignment are the team owner's call — the same
     * boundary Team Members uses for membership state changes.
     */
    private function authorizeTeamOwner(?Team $team): void
    {
        abort_unless(
            $team !== null && $team->isOwnedBy($this->actingUser()),
            403,
            'Only the team owner may manage its roles.',
        );
    }

    private function holdsRole(Role $role, TeamMember $member): bool
    {
        return $role->members()
            ->where('team_members.id', $member->getKey())
            ->exists();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // SQLSTATE 23000 (SQLite, MySQL) / 23505 (PostgreSQL) are the integrity
        // violations a duplicate row produces.
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?team_id[]=a&team_id[]=b) must not reach the query builder.
     * Mirrors TeamMembersController::queryScalar().
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
     * @return array{team_id: int, name: string, description: ?string}
     */
    private function validatedRole(Request $request): array
    {
        // The scoped unique rule needs the target team up front. An array or
        // missing team_id can never match a real team, so narrowing it to a
        // scalar keeps the rule's WHERE clause off the query builder's error
        // path while the exists rule reports the real problem.
        $teamId = $request->input('team_id');
        $scopeTeamId = is_scalar($teamId) ? $teamId : null;

        return $request->validate([
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('roles', 'name')->where('team_id', $scopeTeamId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'team_id.exists' => 'That team no longer exists.',
            'name.unique' => 'That team already has a role with that name.',
        ]);
    }

    /**
     * @return array{id: int, team_id: int, team_name: ?string, name: string, description: ?string, assignment_count: int, assignments: array<int, array{team_member_id: int, user_id: int, user_name: ?string, user_email: ?string, member_status: ?string, assigned_at: ?string}>, created_at: ?string}
     */
    private function rolePayload(Role $role): array
    {
        return [
            'id' => (int) $role->getKey(),
            'team_id' => (int) $role->team_id,
            'team_name' => $role->team?->name,
            'name' => $role->name,
            'description' => $role->description,
            'assignment_count' => $role->members->count(),
            'assignments' => $role->members
                ->map(fn (TeamMember $member): array => [
                    'team_member_id' => (int) $member->getKey(),
                    'user_id' => (int) $member->user_id,
                    'user_name' => $member->user?->name,
                    'user_email' => $member->user?->email,
                    'member_status' => $member->status,
                    'assigned_at' => $member->pivot?->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'created_at' => $role->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, team_id: int, team_name: ?string, user_id: int, user_name: ?string, user_email: ?string, status: string}
     */
    private function candidatePayload(TeamMember $member): array
    {
        return [
            'id' => (int) $member->getKey(),
            'team_id' => (int) $member->team_id,
            'team_name' => $member->team?->name,
            'user_id' => (int) $member->user_id,
            'user_name' => $member->user?->name,
            'user_email' => $member->user?->email,
            'status' => $member->status,
        ];
    }
}
