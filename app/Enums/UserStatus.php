<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Suspended = 'suspended';
    case Banned    = 'banned';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Pending   => __('status.pending'),
            self::Active    => __('status.active'),
            self::Suspended => __('status.suspended'),
            self::Banned    => __('status.banned'),
            self::Rejected  => __('status.rejected'),
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending   => 'yellow',
            self::Active    => 'green',
            self::Suspended => 'orange',
            self::Banned    => 'red',
            self::Rejected  => 'gray',
        };
    }
}
