<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Models;

use App\Modules\TrustSafety\Contracts\RestrictionReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property RestrictionReason $reason
 * @property string $given_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_by
 */
final class Strike extends Model
{
    use HasUlids;

    public const null UPDATED_AT = null;

    /** Three active strikes flag the account for a ban decision (plan M8a, use case 19). */
    public const int FLAG_AT = 3;

    protected $guarded = ['*'];

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => RestrictionReason::class,
            'created_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }
}
