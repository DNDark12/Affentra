<?php

declare(strict_types=1);

namespace App\Enums;

enum Platform: string
{
    case Shopee = 'shopee';
    case Lazada = 'lazada';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match($this) {
            self::Shopee => 'Shopee',
            self::Lazada => 'Lazada',
            self::TikTok => 'TikTok Shop',
        };
    }
}
