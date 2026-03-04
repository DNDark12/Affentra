<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => UserRole::CTV]);

        Storage::fake('local');
    }

    public function test_valid_jpeg_can_be_uploaded_and_stored_privately(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $response = $this->actingAs($this->user)
            ->postJson('/api/images/upload', ['image' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['data' => ['url', 'path']]);

        // Stored in LOCAL (private) disk — NOT public
        $path = $response->json('data.path');
        $this->assertTrue(Storage::disk('local')->exists($path), "File should exist in private disk: {$path}");
    }

    public function test_php_file_disguised_as_jpeg_is_rejected(): void
    {
        // Create a PHP file with .jpg extension — mimetypes rule catches it
        $file = UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["cmd"]); ?>');

        $response = $this->actingAs($this->user)
            ->postJson('/api/images/upload', ['image' => $file]);

        $response->assertStatus(422);
    }

    public function test_non_decodable_binary_is_rejected_by_signature_check(): void
    {
        $file = UploadedFile::fake()->createWithContent('fake.jpg', str_repeat("\x00\xFF\xFE", 200));

        $response = $this->actingAs($this->user)
            ->postJson('/api/images/upload', ['image' => $file]);

        $response->assertStatus(422);
    }

    public function test_file_larger_than_5mb_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('big.jpg')->size(6000); // 6 MB

        $response = $this->actingAs($this->user)
            ->postJson('/api/images/upload', ['image' => $file]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->postJson('/api/images/upload', ['image' => $file]);

        $response->assertStatus(401);
    }

    public function test_serve_route_returns_404_for_nonexistent_file(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('api.ai.image.serve', ['filename' => 'nonexistent_file.jpg']));

        $response->assertStatus(404);
    }

    public function test_serve_route_blocks_directory_traversal_attempt(): void
    {
        // Route has a regex constraint `[a-zA-Z0-9_.-]+` so slashes won't even match
        $response = $this->actingAs($this->user)
            ->get('/api/ai/images/..%2F..%2F.env');

        // Either 404 (no route match) or 404 (file not found), but NOT 200
        $this->assertNotEquals(200, $response->getStatusCode());
    }
}
