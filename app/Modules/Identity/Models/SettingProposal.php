<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Contracts\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin's proposed change to a platform setting, waiting for a second admin (canvas
 * AdminSettings: "Grace N. proposed: minimum withdrawal $20 → $15").
 *
 * @property string $id
 * @property Setting $key
 * @property mixed $current_value
 * @property mixed $proposed_value
 * @property string $reason
 * @property string $proposed_by
 * @property CarbonImmutable $proposed_at
 * @property string $status pending, approved, rejected or withdrawn
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 */
final class SettingProposal extends Model
{
    use HasUlids;

    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string WITHDRAWN = 'withdrawn';

    public $timestamps = false;

    protected $table = 'settings_proposals';

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => Setting::class,
            'current_value' => 'json',
            'proposed_value' => 'json',
            'proposed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
