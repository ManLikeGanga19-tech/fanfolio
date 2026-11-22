<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Contracts\Data\VerificationMethod;
use App\Modules\Identity\Contracts\Data\VerificationPurpose;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $provider_ref
 * @property VerificationPurpose $purpose
 * @property VerificationMethod $method
 * @property VerificationStatus $status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable $created_at
 */
final class AgeVerification extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => VerificationPurpose::class,
            'method' => VerificationMethod::class,
            'status' => VerificationStatus::class,
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
