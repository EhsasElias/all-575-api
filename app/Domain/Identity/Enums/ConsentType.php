<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum ConsentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';
}
