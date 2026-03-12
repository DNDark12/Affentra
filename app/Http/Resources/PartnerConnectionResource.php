<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'platform' => $this->resource['platform'],
            'label' => $this->resource['label'],
            'method' => $this->resource['method'],
            'status' => $this->resource['status'],
            'last_sync_at' => $this->resource['last_sync_at'],
            'last_sync_status' => $this->resource['last_sync_status'],
            'sync_health' => $this->resource['sync_health'],
        ];
    }
}
