<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Order\ImportOrderRequest;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    /**
     * Inertia page: Orders listing with filters + summary stats.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->only(['status', 'period', 'search', 'platform']);

        $orders = $this->orderService->listForUser($user, $filters);
        $stats  = $this->orderService->getStats($user, $filters['period'] ?? '30days');

        return Inertia::render('Orders/Index', [
            'orders'  => $orders,
            'stats'   => $stats,
            'filters' => $filters,
        ]);
    }

    /**
     * API: Paginated orders list (for AJAX refresh).
     */
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->only(['status', 'period', 'search', 'platform']);
        $orders  = $this->orderService->listForUser($user, $filters);

        return response()->json(['ok' => true, 'data' => $orders]);
    }

    /**
     * API: Import orders from CSV file.
     * Dispatches ImportOrdersJob to queue.
     */
    public function import(ImportOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $platform = $request->input('platform', 'shopee');

        $syncRun = $this->orderService->startImport(
            user: $user,
            file: $request->file('file'),
            platform: (string) $platform,
        );

        return response()->json([
            'ok'      => true,
            'data'    => ['sync_run_id' => $syncRun->id],
            'message' => 'Import đang được xử lý. Kết quả sẽ cập nhật sau ít phút.',
        ], 202);
    }

    /**
     * API: Check import status.
     */
    public function importStatus(Request $request, int $syncRunId): JsonResponse
    {
        $status = $this->orderService->getImportStatus($request->user(), $syncRunId);
        if ($status === null) {
            return response()->json(['ok' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'ok'   => true,
            'data' => $status,
        ]);
    }
}
