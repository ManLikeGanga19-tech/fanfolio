<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Enums\TopUpStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A fan buying credits through the gateway's hosted checkout (spec "Top-up flow").
 * Credits reach the wallet only when the gateway's signed webhook confirms the payment.
 *
 * @property string $id
 * @property string $fan_id
 * @property string $idempotency_key
 * @property string $gateway
 * @property string|null $gateway_ref
 * @property string|null $checkout_url
 * @property TopUpMethod $method
 * @property int $amount_minor
 * @property string $currency
 * @property int $credits
 * @property int $bonus_credits
 * @property TopUpStatus $status
 * @property string|null $payment_hint
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable $created_at
 */
final class TopUp extends Model
{
    use HasUlids;

    protected $table = 'topups';

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'bonus_credits' => 0];

    /** All the credits the fan receives: the pack and its bonus (D-04). */
    public function totalCredits(): int
    {
        return $this->credits + $this->bonus_credits;
    }

    /** The reference on receipts and in support ("FF-8K2Q-19"): from the id, nothing secret. */
    public function reference(): string
    {
        $tail = strtoupper(substr($this->id, -6));

        return 'FF-'.substr($tail, 0, 4).'-'.substr($tail, 4);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => TopUpMethod::class,
            'amount_minor' => 'integer',
            'credits' => 'integer',
            'bonus_credits' => 'integer',
            'status' => TopUpStatus::class,
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
