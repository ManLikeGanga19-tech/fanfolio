<?php

declare(strict_types=1);

namespace App\Modules\Identity\Gateways\Fake;

use App\Modules\Identity\Contracts\NetworkRiskCheck;
use App\Support\Gateways\FakeDriver;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * D-16 stand-in: addresses listed in `fanfolio.fake.anonymised_ips` count as a VPN or
 * proxy; everything else is clean.
 */
final readonly class FakeNetworkRiskCheck implements FakeDriver, NetworkRiskCheck
{
    public function __construct(private Config $config) {}

    public function isAnonymised(string $ip): bool
    {
        $listed = $this->config->get('fanfolio.fake.anonymised_ips', []);

        return is_array($listed) && in_array($ip, $listed, true);
    }

    public function refuses(string $area): bool
    {
        return $this->config->get('fanfolio.network_risk.refuse.'.$area) === true;
    }
}
