<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_helpers(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'status' => UserStatus::Active, 'depth' => 0, 'path' => '']);
        $leader = User::factory()->create(['role' => UserRole::Leader, 'status' => UserStatus::Active, 'depth' => 0, 'path' => '']);
        $ctv = User::factory()->create(['role' => UserRole::CTV, 'status' => UserStatus::Active, 'depth' => 0, 'path' => '']);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isLeader());

        $this->assertTrue($leader->isLeader());
        $this->assertFalse($leader->isCTV());

        $this->assertTrue($ctv->isCTV());
        $this->assertFalse($ctv->isOwner());

        $this->assertTrue($owner->isActive());
    }

    public function test_get_descendant_ids_with_materialized_path(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'status' => UserStatus::Active, 'depth' => 0, 'path' => '']);
        $owner->path = (string) $owner->id;
        $owner->save();

        $leader1 = User::factory()->create([
            'role' => UserRole::Leader,
            'status' => UserStatus::Active,
            'parent_id' => $owner->id,
            'depth' => 1,
            'path' => $owner->id
        ]);
        $leader1->path = $owner->id . '/' . $leader1->id;
        $leader1->save();

        $ctv1 = User::factory()->create([
            'role' => UserRole::CTV,
            'status' => UserStatus::Active,
            'parent_id' => $leader1->id,
            'depth' => 2,
            'path' => $leader1->path
        ]);
        $ctv1->path = $leader1->path . '/' . $ctv1->id;
        $ctv1->save();

        $ctv2 = User::factory()->create([
            'role' => UserRole::CTV,
            'status' => UserStatus::Active,
            'parent_id' => $leader1->id,
            'depth' => 2,
            'path' => $leader1->path
        ]);
        $ctv2->path = $leader1->path . '/' . $ctv2->id;
        $ctv2->save();

        $leader2 = User::factory()->create([
            'role' => UserRole::Leader,
            'status' => UserStatus::Active,
            'parent_id' => $owner->id,
            'depth' => 1,
            'path' => $owner->id
        ]);
        $leader2->path = $owner->id . '/' . $leader2->id;
        $leader2->save();

        // 1. Owner should have 4 descendants
        $ownerDescendants = $owner->getDescendantIds();
        $this->assertCount(4, $ownerDescendants);
        $this->assertContains($ctv1->id, $ownerDescendants);
        $this->assertContains($ctv2->id, $ownerDescendants);
        $this->assertContains($leader1->id, $ownerDescendants);
        $this->assertContains($leader2->id, $ownerDescendants);

        // 2. Leader1 should have 2 descendants
        $leader1Descendants = $leader1->getDescendantIds();
        $this->assertCount(2, $leader1Descendants);
        $this->assertContains($ctv1->id, $leader1Descendants);
        $this->assertContains($ctv2->id, $leader1Descendants);

        // 3. CTV should have NO descendants
        $this->assertEmpty($ctv1->getDescendantIds());
    }
}
