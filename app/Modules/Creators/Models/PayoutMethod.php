<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Modules\Wallet\Contracts\Data\PayoutRail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank account, USDT (TRC-20) wallet or Paxum account. The details and the name on
 * the account are encrypted (rule 11); display_hint is the only part staff see.
 *
 * @property string $id
 * @property string $creator_id
 * @property PayoutRail $rail
 * @property array<string, string> $details
 * @property string $account_name
 * @property string $display_hint
 * @property bool $name_matches_kyc
 * @property bool $is_default
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
final class PayoutMethod extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = ['creator_id', 'rail', 'details', 'account_name', 'display_hint', 'name_matches_kyc', 'is_default'];

    /** @var list<string> */
    protected $hidden = ['details', 'account_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rail' => PayoutRail::class,
            'details' => 'encrypted:array',
            'account_name' => 'encrypted',
            'name_matches_kyc' => 'boolean',
            'is_default' => 'boolean',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
