<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $secret
 * @property int|null $last_used_timestep
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable $created_at
 */
final class TotpSecret extends Model
{
    use HasUlids;

    protected $table = 'mfa_totp';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['secret'];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_used_timestep' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
