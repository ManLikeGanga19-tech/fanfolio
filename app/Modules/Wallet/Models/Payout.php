<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Enums\PayoutState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A creator's withdrawal (spec "Payout flow").
 *
 * @property string $id
 * @property string $creator_id
 * @property string $idempotency_key
 * @property string $payout_method_id
 * @property PayoutRail $rail
 * @property string $display_hint
 * @property int $amount_minor
 * @property string $currency
 * @property PayoutState $status
 * @property list<string> $flags
 * @property string|null $gateway_ref
 * @property string|null $failure_reason
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable $created_at
 */
final class Payout extends Model
{
    use HasUlids;

    public const string OVER_REVIEW_LIMIT = 'over_review_limit';

    public const string NEW_METHOD = 'new_payout_method';

    public const string NEW_DEVICE = 'new_device';

    public const string NAME_MISMATCH = 'name_mismatch';

    /** How admins read each flag (canvas AdminPayouts "Flags"). */
    public const array FLAG_LABELS = [
        self::OVER_REVIEW_LIMIT => 'Over $1,000',
        self::NEW_METHOD => 'New payout method',
        self::NEW_DEVICE => 'New device',
        self::NAME_MISMATCH => 'Payout name does not match ID',
    ];

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'review', 'flags' => '[]'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rail' => PayoutRail::class,
            'amount_minor' => 'integer',
            'status' => PayoutState::class,
            'flags' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
