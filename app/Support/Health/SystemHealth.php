<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as Queues;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Plan M8a use case 2 (admins only): queue backlog and failed jobs, the scheduler's last
 * run and which gateway drivers are still the Fake stand-ins. Money and media checks
 * (ledger reconciliation, DRM licences, leak matches) join with M3 and M4.
 */
final readonly class SystemHealth
{
    /** Written every minute by the scheduler (routes/console.php). */
    public const string HEARTBEAT_KEY = 'fanfolio:scheduler:heartbeat';

    /** Jobs waiting before the backlog counts as a warning. */
    private const int BACKLOG_WARNING = 100;

    public function __construct(
        private Queues $queues,
        private ConnectionInterface $db,
        private Cache $cache,
    ) {}

    /**
     * @return list<HealthCheck>
     */
    public function checks(): array
    {
        return [$this->queue(), $this->failedJobs(), $this->scheduler(), $this->drivers()];
    }

    public static function beat(Cache $cache): void
    {
        $cache->forever(self::HEARTBEAT_KEY, CarbonImmutable::now()->getTimestamp());
    }

    private function queue(): HealthCheck
    {
        try {
            $waiting = $this->queues->connection()->size();
        } catch (Throwable) {
            return new HealthCheck('Queue', 'Cannot reach the queue', HealthCheck::PROBLEM);
        }

        return new HealthCheck(
            'Queue',
            $waiting === 0 ? 'Nothing waiting' : number_format($waiting).' '.($waiting === 1 ? 'job' : 'jobs').' waiting',
            $waiting >= self::BACKLOG_WARNING ? HealthCheck::WARNING : HealthCheck::OK,
        );
    }

    private function failedJobs(): HealthCheck
    {
        $failed = $this->db->table('failed_jobs')->count();

        return new HealthCheck(
            'Failed jobs',
            $failed === 0 ? 'None' : number_format($failed).' to look at (php artisan queue:failed)',
            $failed === 0 ? HealthCheck::OK : HealthCheck::WARNING,
        );
    }

    private function scheduler(): HealthCheck
    {
        $last = $this->cache->get(self::HEARTBEAT_KEY);

        // The Redis cache store gives numbers back as strings.
        if (! is_int($last) && (! is_string($last) || ! ctype_digit($last))) {
            return new HealthCheck('Scheduler', 'No run recorded yet', HealthCheck::WARNING);
        }

        $at = CarbonImmutable::createFromTimestamp((int) $last);
        $late = $at->lt(CarbonImmutable::now()->subMinutes(5));

        return new HealthCheck('Scheduler', 'Last ran '.$at->diffForHumans(), $late ? HealthCheck::PROBLEM : HealthCheck::OK);
    }

    private function drivers(): HealthCheck
    {
        $fake = [];
        foreach ($this->flatten(config()->array('fanfolio.drivers')) as $name => $driver) {
            if ($driver === 'fake') {
                $fake[] = $name;
            }
        }

        if ($fake === []) {
            return new HealthCheck('Providers', 'All live drivers', HealthCheck::OK);
        }

        // Fake drivers are expected until providers are chosen; they refuse to run in production.
        return new HealthCheck(
            'Providers',
            'Fake stand-ins: '.implode(', ', $fake),
            app()->environment('production') ? HealthCheck::PROBLEM : HealthCheck::OK,
        );
    }

    /**
     * @param  array<array-key, mixed>  $drivers
     * @return array<string, mixed>
     */
    private function flatten(array $drivers, string $prefix = ''): array
    {
        $flat = [];
        foreach ($drivers as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.' '.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $name);
            } else {
                $flat[str_replace('_', ' ', $name)] = $value;
            }
        }

        return $flat;
    }
}
