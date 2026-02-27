<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformConnectionStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
    case Error    = 'error';

    public function label(): string
    {
        return match($this) {
            self::Active   => 'Active',
            self::Inactive => 'Inactive',
            self::Error    => 'Error',
        };
    }
}
