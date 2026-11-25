<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Enums\DisputeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A card holder's dispute of a top-up (canvas AdminDisputes, D-20). The processor has
 * already taken the money back when it arrives; `recovery` records what was taken from
 * the fan's credits and from the sales they paid for, so a won dispute returns it exactly.
 *
 * @property string $id
 * @property string $topup_id
 * @property string $fan_id
 * @property string $gateway
 * @property string $gateway_case_ref
 * @property string $reason
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $three_ds
 * @property CarbonImmutable $respond_by
 * @property DisputeStatus $status
 * @property array{wallet_minor: int, unspent_minor: int, bonus_minor: int, clawed_minor: int, written_off_minor: int, sales: list<string>, transactions: list<string>} $recovery
 * @property list<string>|null $evidence
 * @property CarbonImmutable|null $evidence_sent_at
 * @property string|null $evidence_sent_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $accepted_by
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $created_at
 */
final class Dispute extends Model
{
    use HasUlids;

    public const array REASON_LABELS = [
        'not_recognised' => 'Not recognised',
        'not_as_described' => 'Not as described',
        'fraudulent' => 'Fraudulent card',
        'duplicate' => 'Duplicate charge',
        'other' => 'Other',
    ];

    /** The card holder's words, for the detail line ("reason: cardholder doesn't recognise it"). */
    public const array REASON_SENTENCES = [
        'not_recognised' => 'cardholder doesn\'t recognise it',
        'not_as_described' => 'cardholder says it was not as described',
        'fraudulent' => 'cardholder says the card was used without permission',
        'duplicate' => 'cardholder says they were charged twice',
        'other' => 'no reason given',
    ];

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? self::REASON_LABELS['other'];
    }

    public function reasonSentence(): string
    {
        return self::REASON_SENTENCES[$this->reason] ?? self::REASON_SENTENCES['other'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'respond_by' => 'immutable_datetime',
            'status' => DisputeStatus::class,
            'recovery' => 'array',
            'evidence' => 'array',
            'evidence_sent_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
