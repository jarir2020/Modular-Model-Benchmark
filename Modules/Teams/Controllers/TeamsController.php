<?php

namespace Modules\Teams\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Teams\Models\Team;

class TeamsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => $this->queryScalar($request, 'q'),
        ];

        $teams = Team::query()
            ->with('owner:id,name,email')
            ->ownedBy($this->actingUser()?->getKey())
            ->search($filters['q'])
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => $this->teamPayload($team))
            ->values()
            ->all();

        return view('teams::index', [
            'pageProps' => [
                'initialTeams' => $teams,
                'filters' => $filters,
                // Teams are scoped to the acting user, so only their own teams
                // are listed; ownership is never client-controlled on write.
                'owner' => ($owner = $this->actingUser()) === null ? null : $this->ownerPayload($owner),
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('teams.index'),
                    'store' => route('teams.store'),
                    'update' => route('teams.update', ['team' => '__TEAM_ID__']),
                    'destroy' => route('teams.destroy', ['team' => '__TEAM_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->actingUser();

        // No user means no owner to record, so a team cannot be created yet.
        abort_if($owner === null, 403, 'A signed-in user is required to create a team.');

        $data = $this->validatedTeam($request);

        // Ownership is never client-controlled: a new team always belongs to
        // the acting user.
        $team = Team::query()->create([
            ...$data,
            'owner_id' => $owner->getKey(),
        ]);
        $team->load('owner:id,name,email');

        return response()->json([
            'team' => $this->teamPayload($team),
        ], 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $this->authorizeOwnership($team);

        $team->update($this->validatedTeam($request, $team));
        $team->refresh()->load('owner:id,name,email');

        return response()->json([
            'team' => $this->teamPayload($team),
        ]);
    }

    public function destroy(Team $team): JsonResponse
    {
        $this->authorizeOwnership($team);

        $team->delete();

        return response()->json(null, 204);
    }

    /**
     * The user this module authorizes against, or null when the workspace has
     * no users yet.
     *
     * The host has no login routes yet (the plan defers session auth to a later
     * revision), so resolve the acting user from the session when one is signed
     * in and otherwise fall back to the earliest user as a stand-in owner. This
     * keeps the owner boundary real — every read is scoped and every write is
     * refused for a non-owner — rather than skipping authorization entirely
     * until authentication lands. With no users at all there is no owner to
     * authorize against, so the page renders empty and writes are refused.
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
     * The module's authorization boundary: a team is writable only by its
     * owner. Guessing another team's id must not grant access.
     */
    private function authorizeOwnership(Team $team): void
    {
        abort_unless($team->isOwnedBy($this->actingUser()), 403, 'You do not own this team.');
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?q[]=a&q[]=b) must not reach the query builder.
     * Mirrors TimeEntriesController::queryScalar().
     */
    private function queryScalar(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{name: string, description: ?string}
     */
    private function validatedTeam(Request $request, ?Team $team = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                // Names are unique across the app so "a team" is unambiguous;
                // editing a team keeps its own name valid.
                Rule::unique('teams', 'name')->ignore($team?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.unique' => 'A team with that name already exists.',
        ]);
    }

    /**
     * @return array{id: int, name: string, description: ?string, owner_id: int, owner_name: ?string, member_count: int, created_at: ?string}
     */
    private function teamPayload(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'description' => $team->description,
            'owner_id' => (int) $team->owner_id,
            'owner_name' => $team->owner?->name,
            // Membership arrives with module 12; count stays 0 until then rather
            // than guessing at a pivot that does not exist yet.
            'member_count' => 0,
            'created_at' => $team->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function ownerPayload(User $user): array
    {
        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}