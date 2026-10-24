<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use RuntimeException;

final class InvalidWebhook extends RuntimeException {}
