<?php

namespace Modules\TeamMembers\Controllers;

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
use Modules\TeamMembers\Models\TeamMember;
use Modules\Teams\Models\Team;

class TeamMembersController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actingUser();

        $filters = [
            'team_id' => $this->queryScalar($request, 'team_id'),
            'status' => $this->queryScalar($request, 'status'),
            'q' => $this->queryScalar($request, 'q'),
        ];

        // Read scope: a user may see the roster of teams they own and of teams
        // they belong to — nothing else. Guessing another team's id returns an
        // empty page rather than that team's membership.
        $visibleTeamIds = $this->visibleTeamIds($actor);

        $members = TeamMember::query()
            ->with(['team:id,name,owner_id', 'user:id,name,email'])
            ->whereIn('team_id', $visibleTeamIds)
            ->forTeam($filters['team_id'])
            ->withStatus($filters['status'])
            ->search($filters['q'])
            ->orderBy('team_id')
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $member): array => $this->memberPayload($member))
            ->values()
            ->all();

        $teams = Team::query()
            ->whereIn('id', $visibleTeamIds)
            ->orderBy('name')
            ->get(['id', 'name', 'owner_id'])
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'owner_id' => (int) $team->owner_id,
                // Membership changes are owner-only, so the page needs to know
                // which of the visible teams the acting user may administer.
                'is_owned' => $team->isOwnedBy($actor),
            ])
            ->values()
            ->all();

        return view('team-members::index', [
            'pageProps' => [
                'initialMembers' => $members,
                'filters' => $filters,
                'teams' => $teams,
                'users' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $user): array => [
                        'id' => (int) $user->getKey(),
                        'name' => $user->name,
                        'email' => $user->email,
                    ])
                    ->values()
                    ->all(),
                'statuses' => TeamMember::STATUSES,
                'actingUserId' => $actor === null ? null : (int) $actor->getKey(),
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('team-members.index'),
                    'store' => route('team-members.store'),
                    'update' => route('team-members.update', ['teamMember' => '__MEMBER_ID__']),
                    'destroy' => route('team-members.destroy', ['teamMember' => '__MEMBER_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actingUser();

        abort_if($actor === null, 403, 'A signed-in user is required to add team members.');

        $data = $this->validatedMembership($request);

        $team = Team::query()->findOrFail($data['team_id']);
        $this->authorizeTeamOwner($team);

        // The owner is implicitly on their own team, so a pivot row for them
        // would be a second, conflicting statement of the same membership.
        if ((int) $team->owner_id === (int) $data['user_id']) {
            throw ValidationException::withMessages([
                'user_id' => 'The team owner already belongs to this team.',
            ]);
        }

        if ($this->membershipExists($team, (int) $data['user_id'])) {
            throw ValidationException::withMessages([
                'user_id' => 'That user is already a member of this team.',
            ]);
        }

        try {
            $member = TeamMember::query()->create([
                ...$data,
                'joined_at' => $data['status'] === 'active' ? now() : null,
            ]);
        } catch (QueryException $exception) {
            // The unique (team_id, user_id) index is the hard backstop; a
            // concurrent insert that slipped past the check above is still a
            // duplicate membership, not a server error.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'user_id' => 'That user is already a member of this team.',
            ]);
        }

        $member->load(['team:id,name,owner_id', 'user:id,name,email']);

        return response()->json([
            'member' => $this->memberPayload($member),
        ], 201);
    }

    /**
     * Membership state is the only mutable field: the (team, user) pair is the
     * row's identity, so changing either half would be a different membership
     * and belongs to remove + add, not to an update.
     */
    public function update(Request $request, TeamMember $teamMember): JsonResponse
    {
        $teamMember->loadMissing('team:id,name,owner_id');
        $this->authorizeTeamOwner($teamMember->team);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(TeamMember::STATUSES)],
        ], [
            'status.in' => 'Choose one of: '.implode(', ', TeamMember::STATUSES).'.',
        ]);

        $teamMember->update([
            'status' => $data['status'],
            // Stamp the join date the first time a row becomes active and keep
            // it afterwards, so suspending a member does not rewrite history.
            'joined_at' => $teamMember->joined_at ?? ($data['status'] === 'active' ? now() : null),
        ]);

        $teamMember->refresh()->load(['team:id,name,owner_id', 'user:id,name,email']);

        return response()->json([
            'member' => $this->memberPayload($teamMember),
        ]);
    }

    public function destroy(TeamMember $teamMember): JsonResponse
    {
        // The team owner may remove anyone; a member may remove only themself.
        abort_unless(
            $teamMember->isManagedBy($this->actingUser()),
            403,
            'You may only remove yourself, or members of a team you own.',
        );

        $teamMember->delete();

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
     * Mirrors TeamsController::actingUser().
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
     * The teams whose rosters the acting user may read: the ones they own, plus
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
     * Membership changes are the owner's call. A member may leave on their own
     * (destroy), but may not add, remove or re-state anyone else on the team.
     */
    private function authorizeTeamOwner(?Team $team): void
    {
        abort_unless(
            $team !== null && $team->isOwnedBy($this->actingUser()),
            403,
            'Only the team owner may change its membership.',
        );
    }

    private function membershipExists(Team $team, int $userId): bool
    {
        return TeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', $userId)
            ->exists();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // SQLSTATE 23000 (SQLite, MySQL) / 23505 (PostgreSQL) are the integrity
        // violations a duplicate pivot row produces.
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?status[]=a&status[]=b) must not reach the query builder.
     * Mirrors TeamsController::queryScalar().
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
     * @return array{team_id: int, user_id: int, status: string}
     */
    private function validatedMembership(Request $request): array
    {
        return $request->validate([
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'status' => ['required', 'string', Rule::in(TeamMember::STATUSES)],
        ], [
            'team_id.exists' => 'That team no longer exists.',
            'user_id.exists' => 'That user no longer exists.',
            'status.in' => 'Choose one of: '.implode(', ', TeamMember::STATUSES).'.',
        ]);
    }

    /**
     * @return array{id: int, team_id: int, team_name: ?string, user_id: int, user_name: ?string, user_email: ?string, status: string, joined_at: ?string, created_at: ?string}
     */
    private function memberPayload(TeamMember $member): array
    {
        return [
            'id' => (int) $member->getKey(),
            'team_id' => (int) $member->team_id,
            'team_name' => $member->team?->name,
            'user_id' => (int) $member->user_id,
            'user_name' => $member->user?->name,
            'user_email' => $member->user?->email,
            'status' => $member->status,
            'joined_at' => $member->joined_at?->toIso8601String(),
            'created_at' => $member->created_at?->toIso8601String(),
        ];
    }
}
