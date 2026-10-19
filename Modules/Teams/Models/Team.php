<?php

namespace Modules\Teams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    protected $fillable = [
        'name',
        'description',
        'owner_id',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The module's authorization boundary: only the owner may rename, edit or
     * delete a team. Returns false for a guest so callers never have to null-check.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->owner_id === (int) $user->getKey();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOwnedBy(Builder $query, int|string|null $userId): void
    {
        $query->when(
            $userId !== null && $userId !== '',
            fn (Builder $q): Builder => $q->where('owner_id', $userId),
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when(
            $term !== null && $term !== '',
            fn (Builder $q): Builder => $q->where('name', 'like', '%'.$term.'%'),
        );
    }
}