<?php

declare(strict_types=1);

namespace App\Support\Gateways;

/**
 * Marks a gateway driver as a local/test stand-in. Drivers with this marker refuse to
 * resolve in production.
 */
interface FakeDriver {}
