<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutBatchStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Exported = 'exported';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Nháp',
            self::Finalized => 'Đã chốt',
            self::Exported  => 'Đã xuất',
        };
    }

    public function canDelete(): bool
    {
        return $this === self::Draft;
    }

    public function canFinalize(): bool
    {
        return $this === self::Draft;
    }

    public function canExport(): bool
    {
        return $this === self::Finalized || $this === self::Exported;
    }
}
