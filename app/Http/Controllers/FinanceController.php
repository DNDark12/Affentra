<?php

namespace App\Http\Controllers;

use App\Jobs\Sync\SyncPaymentDataJob;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FinanceController extends Controller
{
    /**
     * Display the finance overview.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $billings = AffiliateBilling::query()
            ->when($user->role !== 'admin', fn($q) => $q->where('user_id', $user->id))
            ->orderBy('period_start', 'desc')
            ->paginate(20)
            ->withQueryString();

        $payouts = AffiliatePayout::query()
            ->when($user->role !== 'admin', fn($q) => $q->where('user_id', $user->id))
            ->orderBy('payout_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Finance/Index', [
            'billings' => $billings,
            'payouts' => $payouts,
        ]);
    }

    /**
     * Trigger a manual sync for payment data.
     */
    public function sync(Request $request)
    {
        $user = $request->user();
        
        $connections = PlatformConnection::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('method', 'cookie')
            ->get();

        if ($connections->isEmpty()) {
            return back()->with('error', 'Không tìm thấy kết nối Shopee Cookie nào đang hoạt động.');
        }

        foreach ($connections as $connection) {
            SyncPaymentDataJob::dispatch($connection);
        }

        return back()->with('success', 'Đã bắt đầu tiến trình đồng bộ dữ liệu tài chính.');
    }
}
