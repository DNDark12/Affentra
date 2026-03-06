<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\AlertIncident;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                    'avatar' => $user->avatar,
                    'phone'  => $user->phone,
                    'role'   => $user->isPartner()
                        ? UserRole::Partner->value
                        : (($user->role instanceof \UnitEnum) ? $user->role->value : (string) $user->role),
                    'status' => $user->status,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'status'  => $request->session()->get('status'),
                'sync_error' => $request->session()->get('sync_error'),
            ],
            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy())->toArray(), [
                    'location' => $request->url(),
                ]);
            },
            'constants' => config('affentra', []),
            'alerts' => function () use ($request) {
                /** @var User|null $user */
                $user = $request->user();
                if (! $user) {
                    return ['unseen_count' => 0, 'open_count' => 0];
                }

                $unseenCount = AlertIncident::query()
                    ->where('user_id', $user->id)
                    ->whereNull('seen_at')
                    ->whereNull('resolved_at')
                    ->count();

                $openCount = AlertIncident::query()
                    ->where('user_id', $user->id)
                    ->whereNull('resolved_at')
                    ->count();

                return [
                    'unseen_count' => $unseenCount,
                    'open_count' => $openCount,
                ];
            },
            'sync_status' => function () use ($request) {
                /** @var User|null $user */
                $user = $request->user();
                if (! $user) {
                    return null;
                }

                $connections = PlatformConnection::query()
                    ->where('user_id', $user->id)
                    ->get([
                        'id',
                        'status',
                        'sync_mode',
                        'last_sync_at',
                        'last_sync_status',
                        'last_error_at',
                    ]);

                if ($connections->isEmpty()) {
                    return ['status' => 'fresh', 'lastSyncAt' => null];
                }

                // Removed persistent failure logic to prevent banner sticking.
                // Failures should be handled via flash('sync_error') or local component state.


                // Delayed warning only applies to active scheduled connections.
                $scheduledConnections = $connections->filter(static function (PlatformConnection $connection): bool {
                    return $connection->status === 'active' && $connection->sync_mode === 'scheduled';
                });

                if ($scheduledConnections->isNotEmpty()) {
                    $latestScheduledSyncAt = $scheduledConnections
                        ->pluck('last_sync_at')
                        ->filter()
                        ->sortDesc()
                        ->first();

                    if ($latestScheduledSyncAt === null || $latestScheduledSyncAt->diffInMinutes(now()) > 120) {
                        return ['status' => 'delayed', 'nextSyncIn' => 'soon'];
                    }
                }

                $latestSyncAt = $connections
                    ->pluck('last_sync_at')
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'status' => 'fresh',
                    'lastSyncAt' => $latestSyncAt?->diffForHumans(),
                ];
            },
            'ui' => function () use ($request) {
                /** @var User|null $user */
                $user = $request->user();
                if (! $user) {
                    return [
                        'current_platform' => 'shopee',
                        'current_platform_label' => 'Shopee',
                    ];
                }

                $activeConnection = PlatformConnection::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->orderByDesc('last_sync_at')
                    ->orderByDesc('id')
                    ->first(['platform']);

                if (! $activeConnection) {
                    $activeConnection = PlatformConnection::query()
                        ->where('user_id', $user->id)
                        ->orderByDesc('last_sync_at')
                        ->orderByDesc('id')
                        ->first(['platform']);
                }

                $platform = strtolower((string) ($activeConnection?->platform ?? 'shopee'));
                /** @var array<string, string> $platformLabels */
                $platformLabels = config('affentra.platforms', []);

                return [
                    'current_platform' => $platform,
                    'current_platform_label' => $platformLabels[$platform] ?? ucfirst($platform),
                ];
            },
        ]);
    }
}
