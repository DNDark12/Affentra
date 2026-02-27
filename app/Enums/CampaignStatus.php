<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended  = 'ended';

    public function label(): string
    {
        return match($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Ended  => 'Ended',
        };
    }
}
