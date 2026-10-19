<?php

namespace Modules\Invitations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Invitations\Models\Invitation;
use Modules\Teams\Models\Team;

class InvitationsController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actingUser();

        $filters = [
            'team_id' => $this->queryScalar($request, 'team_id'),
            'q' => $this->queryScalar($request, 'q'),
        ];

        $visibleTeamIds = $this->visibleTeamIds($actor);

        $invitations = Invitation::query()
            ->with(['team:id,name,owner_id', 'inviter:id,name,email'])
            ->whereIn('team_id', $visibleTeamIds)
            ->forTeam($filters['team_id'])
            ->search($filters['q'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $inv) => $this->payload($inv))
            ->values()
            ->all();

        $teams = Team::query()
            ->whereIn('id', $visibleTeamIds)
            ->orderBy('name')
            ->get(['id', 'name', 'owner_id'])
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'owner_id' => (int) $team->owner_id,
                'is_owned' => $team->isOwnedBy($actor),
            ])
            ->values()
            ->all();

        return view('invitations::index', [
            'pageProps' => [
                'initialInvitations' => $invitations,
                'filters' => $filters,
                'teams' => $teams,
                'actingUserId' => $actor === null ? null : (int) $actor->getKey(),
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('invitations.index'),
                    'store' => route('invitations.store'),
                    'accept' => route('invitations.accept', ['invitation' => '__ID__']),
                    'revoke' => route('invitations.revoke', ['invitation' => '__ID__']),
                    'destroy' => route('invitations.destroy', ['invitation' => '__ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actingUser();
        abort_if($actor === null, 403);

        $data = $request->validate([
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')],
            'email' => ['required', 'email', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $team = Team::query()->findOrFail($data['team_id']);
        abort_unless($team->isOwnedBy($actor), 403);

        $expiresIn = (int) ($data['expires_in_days'] ?? 7);

        $invitation = Invitation::query()->create([
            'team_id' => $team->id,
            'invited_by' => $actor->getKey(),
            'email' => $data['email'],
            'token' => Str::random(64),
            'expires_at' => now()->addDays($expiresIn),
        ]);

        $invitation->load(['team:id,name,owner_id', 'inviter:id,name,email']);

        return response()->json(['invitation' => $this->payload($invitation)], 201);
    }

    public function accept(Invitation $invitation): JsonResponse
    {
        $actor = $this->actingUser();
        abort_if($actor === null, 403);

        if (! $invitation->isValid()) {
            return response()->json(['message' => 'Invitation is expired or already used.'], 422);
        }

        if (strcasecmp($invitation->email, (string) $actor->email) !== 0) {
            return response()->json(['message' => 'This invitation is for a different email address.'], 403);
        }

        // Create membership if not exists
        $exists = \Modules\TeamMembers\Models\TeamMember::query()
            ->where('team_id', $invitation->team_id)
            ->where('user_id', $actor->getKey())
            ->exists();

        if (! $exists) {
            \Modules\TeamMembers\Models\TeamMember::query()->create([
                'team_id' => $invitation->team_id,
                'user_id' => $actor->getKey(),
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $invitation->update(['accepted_at' => now()]);

        $invitation->load(['team:id,name,owner_id', 'inviter:id,name,email']);
        return response()->json(['invitation' => $this->payload($invitation)]);
    }

    public function revoke(Invitation $invitation): JsonResponse
    {
        $team = $invitation->team;
        abort_unless($team && $team->isOwnedBy($this->actingUser()), 403);
        $invitation->update(['revoked_at' => now()]);
        $invitation->load(['team:id,name,owner_id', 'inviter:id,name,email']);
        return response()->json(['invitation' => $this->payload($invitation)]);
    }

    public function destroy(Invitation $invitation): JsonResponse
    {
        $team = $invitation->team;
        abort_unless($team && $team->isOwnedBy($this->actingUser()), 403);
        $invitation->delete();
        return response()->json(null, 204);
    }

    private function actingUser(): ?User
    {
        $user = Auth::user();
        if ($user instanceof User) {
            return $user;
        }
        return User::query()->orderBy('id')->first();
    }

    private function visibleTeamIds(?User $actor): \Illuminate\Support\Collection
    {
        if ($actor === null) {
            return collect();
        }
        $userId = $actor->getKey();
        return \Modules\Teams\Models\Team::query()
            ->where('owner_id', $userId)
            ->pluck('id')
            ->merge(\Modules\TeamMembers\Models\TeamMember::query()->where('user_id', $userId)->pluck('team_id'))
            ->unique()
            ->values();
    }

    private function queryScalar(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    private function payload(Invitation $inv): array
    {
        return [
            'id' => (int) $inv->getKey(),
            'team_id' => (int) $inv->team_id,
            'team_name' => $inv->team?->name,
            'email' => $inv->email,
            'token' => $inv->token,
            'expires_at' => $inv->expires_at?->toIso8601String(),
            'accepted_at' => $inv->accepted_at?->toIso8601String(),
            'revoked_at' => $inv->revoked_at?->toIso8601String(),
            'is_valid' => $inv->isValid(),
            'inviter' => $inv->inviter?->name,
            'created_at' => $inv->created_at?->toIso8601String(),
        ];
    }
}