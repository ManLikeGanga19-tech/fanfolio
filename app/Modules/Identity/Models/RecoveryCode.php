<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property CarbonImmutable|null $used_at
 */
final class RecoveryCode extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'mfa_recovery_codes';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
