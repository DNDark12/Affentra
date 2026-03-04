<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prettus\Repository\Eloquent\BaseRepository;

class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    public function model(): string
    {
        return Order::class;
    }

    /**
     * @inheritDoc
     */
    public function listForUsers(array $userIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['user:id,name'])
            ->with(['platformConnection:id,label,platform'])
            ->whereIn('user_id', $userIds)
            ->latest('ordered_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }

        if (! empty($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($needle): void {
                $q->where('order_code', 'LIKE', $needle)
                    ->orWhere('external_order_id', 'LIKE', $needle)
                    ->orWhere('shop_name', 'LIKE', $needle)
                    ->orWhere('product_name', 'LIKE', $needle);
            });
        }

        if (! empty($filters['period'])) {
            $from = match ($filters['period']) {
                '7days'  => now()->subDays(7),
                '90days' => now()->subDays(90),
                default  => now()->subDays(30),
            };
            $query->where('ordered_at', '>=', $from);
        }

        return $query->paginate($perPage);
    }

    /**
     * @inheritDoc
     */
    public function upsertFromImport(array $rows): array
    {
        $upserted = 0;
        $failed   = 0;

        // Chunk to avoid memory spike and DB payload size limits
        foreach (array_chunk($rows, 1000) as $chunk) {
            try {
                $affected = DB::table('orders')->upsert(
                    $chunk,
                    ['platform', 'order_code'],   // unique key for idempotent upsert
                    ['status', 'order_amount', 'commission', 'ordered_at', 'approved_at', 'user_id', 'campaign_id', 'tracking_link_id', 'updated_at'],
                );
                $upserted += $affected;
            } catch (\Throwable $e) {
                Log::error('Order upsert chunk failed', [
                    'chunk_size' => count($chunk),
                    'error'      => $e->getMessage(),
                ]);
                $failed += count($chunk);
            }
        }

        return ['upserted' => $upserted, 'failed' => $failed];
    }

    /**
     * @inheritDoc
     */
    public function getStatsByUsers(array $userIds, string $period = '30days'): array
    {
        $from = match ($period) {
            '7days'  => now()->subDays(7)->toDateString(),
            '90days' => now()->subDays(90)->toDateString(),
            default  => now()->subDays(30)->toDateString(),
        };

        $result = $this->model->newQuery()
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_orders")
            ->selectRaw('COALESCE(SUM(commission), 0) as total_commission')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total_amount')
            ->whereIn('user_id', $userIds)
            ->where('ordered_at', '>=', $from)
            ->first();

        return [
            'total_orders'     => (int) $result->total_orders,
            'approved_orders'  => (int) $result->approved_orders,
            'total_commission' => (float) $result->total_commission,
            'total_amount'     => (float) $result->total_amount,
        ];
    }
}
