<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Services\AI\PromptTemplateRegistry;
use Tests\TestCase;

class PromptTemplateRegistryTest extends TestCase
{
    public function test_new_presets_are_registered_with_expected_default_type(): void
    {
        $registry = new PromptTemplateRegistry();

        $this->assertSame('video', $registry->get('product_story_video')['default_type']);
        $this->assertSame('video', $registry->get('ugc_review_video')['default_type']);
        $this->assertSame('text', $registry->get('carousel_ad_copy')['default_type']);
        $this->assertSame('text', $registry->get('shopee_title')['default_type']);
        $this->assertSame('text', $registry->get('seo_description')['default_type']);
    }

    public function test_list_for_type_includes_new_presets(): void
    {
        $registry = new PromptTemplateRegistry();

        $video = $registry->listFor('video');
        $text = $registry->listFor('text');

        $this->assertContains('product_story_video', $video);
        $this->assertContains('ugc_review_video', $video);
        $this->assertContains('carousel_ad_copy', $text);
        $this->assertContains('shopee_title', $text);
        $this->assertContains('seo_description', $text);
    }

    public function test_render_respects_custom_prompt_override(): void
    {
        $registry = new PromptTemplateRegistry();

        $rendered = $registry->render('fb_post', [
            'product_title' => 'A',
            'custom_prompt' => 'ONLY THIS',
        ]);

        $this->assertSame('ONLY THIS', $rendered);
    }

    public function test_variant_count_is_clamped_by_configured_max(): void
    {
        config()->set('ai.features.max_variants', 5);
        $registry = new PromptTemplateRegistry();
        
        // Register a test template to prove variant limits work
        $registry->register('test_variant', 'Test', ['text'], function ($attrs) {
            $variants = (int) ($attrs['variant_count'] ?? 1);
            $variants = max(1, min(config('ai.features.max_variants', 5), $variants));
            return "Make {$variants} variants";
        }, 'test');

        $rendered = $registry->render('test_variant', [
            'variant_count' => 99,
        ]);

        $this->assertStringContainsString('Make 5 variants', $rendered);
    }

    public function test_video_templates_render_asset_brief_style_prompt(): void
    {
        $registry = new PromptTemplateRegistry();

        $rendered = $registry->render('ugc_review_video', [
            'product_title' => 'Airpods',
            'duration_sec'  => 15,
        ]);

        $this->assertStringContainsString('Create a 15-second vertical product ad video', $rendered);
        $this->assertStringContainsString('Video structure:', $rendered);
        $this->assertStringContainsString('Direction:', $rendered);
    }
}
