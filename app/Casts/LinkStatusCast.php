<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\LinkStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class LinkStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): LinkStatus
    {
        $normalized = $this->normalize((string) $value);

        return LinkStatus::tryFrom($normalized) ?? LinkStatus::Archived;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof LinkStatus) {
            return $value->value;
        }

        return $this->normalize((string) $value);
    }

    private function normalize(string $status): string
    {
        return $status === 'inactive'
            ? LinkStatus::Paused->value
            : $status;
    }
}
