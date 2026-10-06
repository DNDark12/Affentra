<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DataTransferObjects\CapabilitySet;
use App\Models\PlatformConnection;
use Illuminate\Support\Carbon;

interface IntegrationContract
{
    /**
     * The source-of-truth for what this integration supports.
     * UI toggles and features are gated by this CapabilitySet.
     */
    public function capabilities(): CapabilitySet;

    /**
     * Whether this integration supports scheduled sync.
     */
    public function supportsAutoSync(): bool;

    /**
     * Test that the given connection credentials are valid.
     *
     * @throws \RuntimeException on auth failure
     */
    public function testConnection(PlatformConnection $connection): bool;

    /**
     * Fetch affiliate report (orders/conversions) from the platform.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchReport(PlatformConnection $connection, Carbon $since, Carbon $until): array;

    /**
     * Fetch raw click report from the platform.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchClickReport(PlatformConnection $connection, Carbon $since, Carbon $until): array;

    /**
     * Fetch campaigns from the platform.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaigns(PlatformConnection $connection): array;

    public function fetchBillings(PlatformConnection $connection, Carbon $since, Carbon $until): array;

    public function fetchPayouts(PlatformConnection $connection, Carbon $since, Carbon $until): array;

    public function fetchBillFeeInvoices(PlatformConnection $connection, Carbon $since, Carbon $until): array;

    /**
     * Fetch product category tree for offer filtering (if supported).
     *
     * @return list<array{catId: int, catName: string, children?: list<mixed>}>
     */
    public function getCategories(PlatformConnection $connection): array;

    /**
     * Fetch a single offer detail for detail page and link generation.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getOfferDetail(PlatformConnection $connection, string $offerId, array $filters = []): array;

    /**
     * Fetch product offers for discovery (if supported).
     *
     * @param  array<string, mixed>  $filters  (shopId, itemId, productCatId, keyword, page, limit)
     * @return array<string, mixed>  Raw API response
     */
    public function getOffers(PlatformConnection $connection, array $filters): array;

    /**
     * Generate a short affiliate link (if supported).
     *
     * @param  array<string, mixed>  $context  Platform-specific params (e.g. 'sub_id', 'product_id')
     * @return string|null  The generated short URL, or null if unsupported
     */
    public function generateShortLink(PlatformConnection $connection, string $originalUrl, array $context = []): ?string;
}
