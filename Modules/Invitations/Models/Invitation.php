<?php

namespace Modules\Invitations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Teams\Models\Team;

class Invitation extends Model
{
    protected $fillable = [
        'team_id',
        'invited_by',
        'email',
        'token',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->accepted_at !== null || $this->revoked_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public function scopeForTeam(Builder $query, int|string|null $teamId): void
    {
        $query->when(
            $teamId !== null && $teamId !== '',
            fn (Builder $q): Builder => $q->where('team_id', $teamId),
        );
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when(
            $term !== null && $term !== '',
            function (Builder $q) use ($term): void {
                $like = '%'.$term.'%';
                $q->where(function (Builder $inner) use ($like): void {
                    $inner->where('email', 'like', $like)
                        ->orWhereHas('team', fn (Builder $t) => $t->where('name', 'like', $like));
                });
            },
        );
    }
}