<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified gateway webhook, stored raw (encrypted) once per event id and processed
 * on the payments queue (spec "Inbound webhooks", SEC-13).
 *
 * @property string $id
 * @property string $gateway
 * @property string $event_id
 * @property GatewayEventType $type
 * @property string $gateway_ref
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $payment_hint
 * @property string $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $error
 */
final class GatewayWebhook extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GatewayEventType::class,
            'amount_minor' => 'integer',
            'payload' => 'encrypted',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
