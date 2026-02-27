<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Owner  = 'owner';
    case Leader = 'leader';
    case CTV    = 'ctv';

    public function label(): string
    {
        return match($this) {
            self::Owner  => 'Owner',
            self::Leader => 'Leader',
            self::CTV    => 'CTV',
        };
    }

    public function canManagePartners(): bool
    {
        return $this === self::Owner || $this === self::Leader;
    }

    public function canManageCampaigns(): bool
    {
        return $this === self::Owner || $this === self::Leader;
    }
}
