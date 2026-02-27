<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Contracts\Services\IntegrationContract;
use App\DataTransferObjects\CapabilitySet;
use App\Models\PlatformConnection;
use Illuminate\Support\Carbon;

/**
 * Base adapter for platform integrations.
 * Provides default "unsupported" implementations for optional methods.
 * Each platform adapter extends this and overrides what it supports.
 */
abstract class BaseIntegration implements IntegrationContract
{
    abstract public function capabilities(): CapabilitySet;

    public function supportsAutoSync(): bool
    {
        return $this->capabilities()->supportsAutoSync;
    }

    public function testConnection(PlatformConnection $connection): bool
    {
        throw new \RuntimeException(
            static::class . ' does not implement testConnection()'
        );
    }

    public function fetchReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        throw new \RuntimeException(
            static::class . ' does not implement fetchReport()'
        );
    }

    public function fetchClickReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        return [];
    }

    public function fetchCampaigns(PlatformConnection $connection): array
    {
        return [];
    }

    public function fetchBillings(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        return [];
    }

    public function fetchPayouts(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        return [];
    }

    public function fetchBillFeeInvoices(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        return [];
    }

    public function getOffers(PlatformConnection $connection, array $filters): array
    {
        if (! $this->capabilities()->supportsOfferDiscovery) {
            return ['items' => [], 'total' => 0];
        }

        throw new \RuntimeException(
            static::class . ' does not implement getOffers()'
        );
    }

    public function generateShortLink(PlatformConnection $connection, string $originalUrl, ?string $subId = null): ?string
    {
        if (! $this->capabilities()->supportsShortLink) {
            return null;
        }

        throw new \RuntimeException(
            static::class . ' does not implement generateShortLink()'
        );
    }

    /**
     * Refresh the capabilities snapshot stored in the DB.
     */
    public function refreshCapabilitiesSnapshot(PlatformConnection $connection): void
    {
        $connection->update([
            'capabilities' => $this->capabilities()->toArray(),
        ]);
    }
}
