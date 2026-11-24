<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A creator application and, once approved, the creator page.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $handle
 * @property string|null $display_name
 * @property string|null $category
 * @property bool $is_adult
 * @property KycStatus $kyc_status
 * @property CreatorStatus $status
 * @property CarbonImmutable|null $terms_accepted_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $suspended_at
 */
final class Creator extends Model
{
    /** @use HasFactory<CreatorFactory> */
    use HasFactory;

    use HasUlids;

    /** @var list<string> */
    protected $fillable = ['user_id', 'handle', 'display_name', 'category', 'is_adult'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_adult' => false,
        'kyc_status' => 'not_started',
        'status' => 'onboarding',
    ];

    public function hasProfile(): bool
    {
        return $this->handle !== null && $this->display_name !== null && $this->category !== null;
    }

    /** Initials shown until profile photos arrive with media scanning in M4 (decision D-08). */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->display_name)) ?: [];
        $letters = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(array_filter($words), 0, 2));

        return implode('', $letters) ?: '?';
    }

    protected static function newFactory(): CreatorFactory
    {
        return CreatorFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_adult' => 'boolean',
            'kyc_status' => KycStatus::class,
            'status' => CreatorStatus::class,
            'terms_accepted_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }
}
