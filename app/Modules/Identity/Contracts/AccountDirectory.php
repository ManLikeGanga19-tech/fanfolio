<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Contracts\Data\AccountSummary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Users & bans board's view of accounts (plan M8a, use cases 14 and 15). Staff
 * search by username, email or ID, but only see an AccountSummary (rule 13).
 */
interface AccountDirectory
{
    public const array FILTERS = ['all', 'creator', 'fan', 'suspended', 'banned'];

    public function find(string $userId): ?AccountSummary;

    /** The public username, with or without the @ (case-insensitive). */
    public function findByUsername(string $username): ?AccountSummary;

    /**
     * Newest first. $onlyIds narrows the list to accounts another module picked (for
     * example the flagged ones); an empty list gives no results.
     *
     * @param  value-of<self::FILTERS>  $filter
     * @param  list<string>|null  $onlyIds
     * @return LengthAwarePaginator<int, AccountSummary>
     */
    public function search(string $term, string $filter, ?array $onlyIds, int $perPage, int $page): LengthAwarePaginator;

    /**
     * @return array{fan: int, creator: int, banned_since: int}
     */
    public function counts(CarbonImmutable $bannedSince): array;
}
