<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Repositories\CampaignRepositoryInterface;
use App\Contracts\Repositories\ClickRepositoryInterface;
use App\Contracts\Repositories\DailyStatRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\PlatformConnectionRepositoryInterface;
use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Contracts\Repositories\SyncRunRepositoryInterface;
use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\Eloquent\CampaignRepository;
use App\Repositories\Eloquent\ClickRepository;
use App\Repositories\Eloquent\DailyStatRepository;
use App\Repositories\Eloquent\OrderRepository;
use App\Repositories\Eloquent\PlatformConnectionRepository;
use App\Repositories\Eloquent\ProfileRepository;
use App\Repositories\Eloquent\SyncRunRepository;
use App\Repositories\Eloquent\TrackingLinkRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        CampaignRepositoryInterface::class          => CampaignRepository::class,
        UserRepositoryInterface::class              => UserRepository::class,
        TrackingLinkRepositoryInterface::class      => TrackingLinkRepository::class,
        ClickRepositoryInterface::class             => ClickRepository::class,
        DailyStatRepositoryInterface::class         => DailyStatRepository::class,
        OrderRepositoryInterface::class             => OrderRepository::class,
        PlatformConnectionRepositoryInterface::class => PlatformConnectionRepository::class,
        SyncRunRepositoryInterface::class           => SyncRunRepository::class,
        ProfileRepositoryInterface::class           => ProfileRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}
