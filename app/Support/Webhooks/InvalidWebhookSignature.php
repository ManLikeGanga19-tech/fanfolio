<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use RuntimeException;

final class InvalidWebhookSignature extends RuntimeException {}
