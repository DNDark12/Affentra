<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\LinkStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Campaign;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── Owner ──────────────────────────────────────────────────────────────
        $owner = User::updateOrCreate(
            ['email' => 'admin@affentra.com'],
            [
                'name'     => 'Admin Owner',
                'password' => Hash::make('password'),
                'role'     => UserRole::Owner,
                'status'   => UserStatus::Active,
                'depth'    => 0,
                'path'     => '',
            ]
        );

        // ── Leader ─────────────────────────────────────────────────────────────
        $leader = User::updateOrCreate(
            ['email' => 'leader@affentra.com'],
            [
                'name'      => 'Nguyễn Văn Leader',
                'password'  => Hash::make('password'),
                'role'      => UserRole::Leader,
                'status'    => UserStatus::Active,
                'parent_id' => $owner->id,
                'depth'     => 1,
                'path'      => (string) $owner->id,
            ]
        );

        // ── CTVs ───────────────────────────────────────────────────────────────
        $ctv1 = User::updateOrCreate(
            ['email' => 'ctv1@affentra.com'],
            [
                'name'      => 'Nguyễn Văn Hiếng',
                'password'  => Hash::make('password'),
                'role'      => UserRole::CTV,
                'status'    => UserStatus::Active,
                'parent_id' => $leader->id,
                'depth'     => 2,
                'path'      => $owner->id . '/' . $leader->id,
            ]
        );

        $ctv2 = User::updateOrCreate(
            ['email' => 'ctv2@affentra.com'],
            [
                'name'      => 'Trần Minh Đức',
                'password'  => Hash::make('password'),
                'role'      => UserRole::CTV,
                'status'    => UserStatus::Active,
                'parent_id' => $leader->id,
                'depth'     => 2,
                'path'      => $owner->id . '/' . $leader->id,
            ]
        );

        // ── Campaigns ──────────────────────────────────────────────────────────
        $campaigns = [
            ['name' => 'Shopee 9.9 Siêu Sale', 'platform' => Platform::Shopee, 'status' => CampaignStatus::Active, 'goal_amount' => 100_000_000],
            ['name' => 'Flash Sale Cuối Tuần',  'platform' => Platform::Shopee, 'status' => CampaignStatus::Active, 'goal_amount' =>  50_000_000],
            ['name' => 'TikTok Shop Oct 2025',  'platform' => Platform::TikTok, 'status' => CampaignStatus::Paused, 'goal_amount' =>  30_000_000],
        ];

        foreach ($campaigns as $c) {
            Campaign::updateOrCreate(
                ['name' => $c['name'], 'user_id' => $owner->id],
                array_merge($c, ['user_id' => $owner->id, 'date_start' => now()->subDays(10), 'date_end' => now()->addDays(20)])
            );
        }

        // ── Tracking Links ─────────────────────────────────────────────────────
        $sampleLinks = [
            ['user_id' => $ctv1->id, 'destination_url' => 'https://shopee.vn/product-a', 'short_code' => 'xa1bc234', 'platform' => Platform::Shopee, 'status' => LinkStatus::Active, 'clicks_count' => 6431],
            ['user_id' => $ctv1->id, 'destination_url' => 'https://shopee.vn/product-b', 'short_code' => 'ya5de678', 'platform' => Platform::Shopee, 'status' => LinkStatus::Active, 'clicks_count' => 1922],
            ['user_id' => $ctv2->id, 'destination_url' => 'https://shopee.vn/product-c', 'short_code' => 'zb9fg012', 'platform' => Platform::Shopee, 'status' => LinkStatus::Active, 'clicks_count' => 3210],
        ];

        foreach ($sampleLinks as $l) {
            TrackingLink::updateOrCreate(['short_code' => $l['short_code']], $l);
        }

        $this->command->info('✅ Affentra seed completed: owner + leader + 2 CTVs + 3 campaigns + 3 tracking links.');
    }
}
