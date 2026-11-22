<?php

declare(strict_types=1);

namespace App\Support\Gateways;

use RuntimeException;

final class GatewayDriverException extends RuntimeException
{
    public static function unknown(string $contract, string $configKey, ?string $driver): self
    {
        return new self(sprintf(
            'No driver "%s" for %s. Set fanfolio.drivers.%s to a registered driver.',
            $driver ?? '(none)',
            $contract,
            $configKey,
        ));
    }

    public static function fakeInProduction(string $contract): self
    {
        return new self(sprintf('The fake driver for %s cannot run in production.', $contract));
    }
}
