<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Modules\Identity\Contracts\Data\VerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One ID and selfie check with the KYC provider. No images or full document numbers
 * (rule 10); legal name and date of birth are encrypted (rule 11).
 *
 * @property string $id
 * @property string $creator_id
 * @property string $provider_ref
 * @property VerificationStatus $status
 * @property bool $liveness_passed
 * @property bool $age_over_18
 * @property string|null $id_type
 * @property string|null $document_last4
 * @property CarbonImmutable|null $document_expires_at
 * @property string|null $legal_name
 * @property string|null $dob
 * @property string|null $failure_reason
 * @property bool $suspected_fraud
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 */
final class CreatorKyc extends Model
{
    use HasUlids;

    protected $table = 'creator_kyc';

    /** @var list<string> */
    protected $fillable = ['creator_id', 'provider_ref', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'liveness_passed' => false,
        'age_over_18' => false,
        'suspected_fraud' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'liveness_passed' => 'boolean',
            'age_over_18' => 'boolean',
            'suspected_fraud' => 'boolean',
            'document_expires_at' => 'immutable_date',
            'legal_name' => 'encrypted',
            'dob' => 'encrypted',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
