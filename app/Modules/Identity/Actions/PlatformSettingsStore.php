<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\PlatformSettings;
use App\Modules\Identity\Contracts\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The changed settings live in `platform_settings`, cached together under one key; a
 * setting without a row reads its config default. Only SettingProposals writes them
 * (after a second admin approves), and it clears the cache in the same step.
 */
final readonly class PlatformSettingsStore implements PlatformSettings
{
    public const string CACHE_KEY = 'identity:platform-settings';

    public function integer(Setting $setting): int
    {
        $value = $this->value($setting);

        return is_int($value) ? $value : (int) $setting->default();
    }

    public function enabled(Setting $setting): bool
    {
        return $this->value($setting) === true;
    }

    public function topUpPacks(): array
    {
        /** @var list<array{amount_minor: int, bonus_bps: int}> $packs */
        $packs = $this->value(Setting::TopUpPacks);

        return $packs;
    }

    /** The setting's value now: the approved change, or its default. */
    public function value(Setting $setting): mixed
    {
        $changed = $this->changed();

        return array_key_exists($setting->value, $changed) ? $changed[$setting->value] : $setting->default();
    }

    /** Writes an approved value; called inside SettingProposals' transaction. */
    public function put(Setting $setting, mixed $value, string $staffId): void
    {
        DB::table('platform_settings')->upsert(
            [['key' => $setting->value, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by' => $staffId, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_by', 'updated_at'],
        );

        DB::afterCommit(static fn () => Cache::forget(self::CACHE_KEY));
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function changed(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(self::CACHE_KEY, static fn (): array => DB::table('platform_settings')
            ->pluck('value', 'key')
            ->map(static fn (mixed $json): mixed => json_decode(is_string($json) ? $json : 'null', true, 8, JSON_THROW_ON_ERROR))
            ->all());
    }
}
