<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Models\PlatformConnection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PartnerConnectionService
{
    /**
     * Retrieve platform connections for a partner with computed sync health.
     * 
     * @return Collection<int, array{
     *   id: int,
     *   platform: string,
     *   label: string,
     *   method: string,
     *   status: string,
     *   last_sync_at: string|null,
     *   last_successful_sync_at: string|null,
     *   last_sync_status: string|null,
     *   sync_health: string
     * }>
     */
    public function connections(User $partner): Collection
    {
        return PlatformConnection::query()
            ->where('user_id', $partner->id)
            ->get()
            ->map(function (PlatformConnection $connection) {
            $lastSyncAt = $connection->last_sync_at;
            $lastStatus = $connection->last_sync_status;
                
                $health = 'healthy';
                
                $statusVal = $connection->status instanceof \UnitEnum ? $connection->status->value : $connection->status;
                if ($statusVal !== 'active') {
                    $health = 'inactive';
                } elseif ($lastStatus !== null && $lastStatus !== 'completed' && $lastStatus !== 'completed_with_warnings') {
                    $health = 'error';
                } elseif ($lastSyncAt === null) {
                    $health = 'pending';
                }

                return [
                    'id' => $connection->id,
                    'platform' => $connection->platform,
                    'label' => $connection->identifier ?? $connection->platform,
                    'method' => $connection->connection_method,
                    'status' => $statusVal,
                    'last_sync_at' => $lastSyncAt?->toIso8601String(),
                    'last_sync_status' => $lastStatus,
                    'sync_health' => $health,
                ];
            });
    }
}
