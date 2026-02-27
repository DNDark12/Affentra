<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Immutable value object representing the capabilities of an integration platform.
 * Source of truth: IntegrationContract::capabilities()
 * Snapshot stored in: platform_connections.capabilities (JSON)
 */
final class CapabilitySet
{
    public function __construct(
        public readonly bool $supportsAutoSync = false,
        public readonly bool $supportsOfferDiscovery = false,
        public readonly bool $supportsShortLink = false,
        public readonly bool $supportsSubId = false,
    ) {}

    /**
     * Create from JSON-decoded array (e.g., from DB column).
     *
     * @param array<string, bool>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self();
        }

        return new self(
            supportsAutoSync: (bool) ($data['supportsAutoSync'] ?? false),
            supportsOfferDiscovery: (bool) ($data['supportsOfferDiscovery'] ?? false),
            supportsShortLink: (bool) ($data['supportsShortLink'] ?? false),
            supportsSubId: (bool) ($data['supportsSubId'] ?? false),
        );
    }

    /**
     * Serialize to array for JSON storage.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'supportsAutoSync' => $this->supportsAutoSync,
            'supportsOfferDiscovery' => $this->supportsOfferDiscovery,
            'supportsShortLink' => $this->supportsShortLink,
            'supportsSubId' => $this->supportsSubId,
        ];
    }
}
