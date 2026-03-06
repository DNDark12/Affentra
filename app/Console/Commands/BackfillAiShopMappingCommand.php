<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TrackingLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillAiShopMappingCommand extends Command
{
    protected $signature = 'ai:shop-mapping:backfill {--dry-run : Compute mapping and print report without writing DB}';

    protected $description = 'Backfill platform_connection_id for tracking_links and content_generations.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('AI shop mapping backfill started' . ($dryRun ? ' (dry-run)' : ''));

        $links = TrackingLink::query()
            ->whereNull('platform_connection_id')
            ->get(['id', 'user_id', 'platform']);

        if ($links->isEmpty()) {
            $this->info('No tracking links require backfill.');
            return self::SUCCESS;
        }

        $linkIds = $links->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $evidenceByLink = $this->buildEvidenceConnectionMap($linkIds);
        $activeConnectionsByOwnerPlatform = $this->buildActiveConnectionMap();

        $mapped = 0;
        $ambiguous = 0;
        $unresolved = 0;
        $updates = [];

        foreach ($links as $link) {
            $linkId = (int) $link->id;
            $ownerId = (int) $link->user_id;
            $platform = $link->platform instanceof \UnitEnum
                ? (string) $link->platform->value
                : (string) $link->platform;
            $evidenceConnections = array_values(array_unique($evidenceByLink[$linkId] ?? []));

            if (count($evidenceConnections) === 1) {
                $updates[$linkId] = (int) $evidenceConnections[0];
                $mapped++;
                continue;
            }

            if (count($evidenceConnections) > 1) {
                $ambiguous++;
                Log::warning('ai_shop_mapping_ambiguous', [
                    'tracking_link_id' => $linkId,
                    'connection_ids' => $evidenceConnections,
                ]);
                continue;
            }

            $candidateKey = $this->ownerPlatformKey($ownerId, $platform);
            $activeConnections = $activeConnectionsByOwnerPlatform[$candidateKey] ?? [];
            if (count($activeConnections) === 1) {
                $updates[$linkId] = (int) $activeConnections[0];
                $mapped++;
                continue;
            }

            $unresolved++;
            Log::warning('ai_shop_mapping_missing', [
                'tracking_link_id' => $linkId,
                'user_id' => $ownerId,
                'platform' => $platform,
            ]);
        }

        $writtenLinkRows = 0;
        if (! $dryRun && $updates !== []) {
            foreach ($updates as $trackingLinkId => $connectionId) {
                $writtenLinkRows += TrackingLink::query()
                    ->where('id', $trackingLinkId)
                    ->whereNull('platform_connection_id')
                    ->update(['platform_connection_id' => $connectionId]);
            }
        }

        $generationQuery = DB::table('content_generations as cg')
            ->join('tracking_links as tl', 'tl.id', '=', 'cg.tracking_link_id')
            ->whereNull('cg.platform_connection_id')
            ->whereNotNull('tl.platform_connection_id');

        $generationCandidates = (clone $generationQuery)->count();
        $generationUpdated = 0;
        if (! $dryRun && $generationCandidates > 0) {
            $driver = DB::getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $generationUpdated = DB::affectingStatement(
                    "UPDATE content_generations cg
                    INNER JOIN tracking_links tl ON tl.id = cg.tracking_link_id
                    SET cg.platform_connection_id = tl.platform_connection_id
                    WHERE cg.platform_connection_id IS NULL AND tl.platform_connection_id IS NOT NULL"
                );
            } else {
                $generationUpdated = DB::table('content_generations')
                    ->whereNull('platform_connection_id')
                    ->whereIn('tracking_link_id', function ($query): void {
                        $query->select('id')
                            ->from('tracking_links')
                            ->whereNotNull('platform_connection_id');
                    })
                    ->update([
                        'platform_connection_id' => DB::raw(
                            '(SELECT platform_connection_id FROM tracking_links WHERE tracking_links.id = content_generations.tracking_link_id)'
                        ),
                    ]);
            }
        }

        $report = [
            'dry_run' => $dryRun,
            'links_scanned' => $links->count(),
            'mapped_count' => $mapped,
            'ambiguous_count' => $ambiguous,
            'unresolved_count' => $unresolved,
            'tracking_links_updated' => $dryRun ? 0 : $writtenLinkRows,
            'content_generations_candidates' => $generationCandidates,
            'content_generations_updated' => $dryRun ? 0 : $generationUpdated,
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('AI shop mapping backfill completed.');

        return self::SUCCESS;
    }

    /**
     * @param list<int> $linkIds
     * @return array<int, list<int>>
     */
    private function buildEvidenceConnectionMap(array $linkIds): array
    {
        if ($linkIds === []) {
            return [];
        }

        $rows = DB::table('orders')
            ->select('tracking_link_id', 'connection_id')
            ->whereIn('tracking_link_id', $linkIds)
            ->whereNotNull('connection_id')
            ->groupBy('tracking_link_id', 'connection_id')
            ->unionAll(
                DB::table('clicks')
                    ->select('tracking_link_id', 'connection_id')
                    ->whereIn('tracking_link_id', $linkIds)
                    ->whereNotNull('connection_id')
                    ->groupBy('tracking_link_id', 'connection_id')
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $trackingLinkId = (int) ($row->tracking_link_id ?? 0);
            $connectionId = (int) ($row->connection_id ?? 0);
            if ($trackingLinkId <= 0 || $connectionId <= 0) {
                continue;
            }

            $map[$trackingLinkId] ??= [];
            $map[$trackingLinkId][] = $connectionId;
        }

        return $map;
    }

    /**
     * @return array<string, list<int>>
     */
    private function buildActiveConnectionMap(): array
    {
        $rows = DB::table('platform_connections')
            ->select('id', 'user_id', 'platform')
            ->where('status', 'active')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $key = $this->ownerPlatformKey((int) $row->user_id, (string) $row->platform);
            $map[$key] ??= [];
            $map[$key][] = (int) $row->id;
        }

        return $map;
    }

    private function ownerPlatformKey(int $userId, string $platform): string
    {
        return $userId . '|' . mb_strtolower($platform);
    }
}
