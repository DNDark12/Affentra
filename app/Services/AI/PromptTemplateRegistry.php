<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Code-based registry of prompt templates.
 *
 * Each template is a callable that receives $attributes and returns a
 * fully-rendered string ready to be sent to the LLM provider.
 *
 * Benefits:
 *  - Version-controlled, testable, DX-friendly.
 *  - No DB round-trips for template fetching.
 *  - platform/preset validation happens here, not scattered in controllers.
 */
class PromptTemplateRegistry
{
    /**
     * @var array<string, array{description: string, types: list<string>, renderer: callable}>
     */
    private array $templates = [];

    public function __construct()
    {
        $this->registerBuiltinTemplates();
    }

    /**
     * Register all built-in templates.
     * Add new entries here when adding presets — no other files need changing.
     */
    private function registerBuiltinTemplates(): void
    {
        $this->register('fb_post_v1', 'Facebook post (short/long)', ['text'], function (array $attrs): string {
            $product  = $attrs['product_title']   ?? 'sản phẩm';
            $price    = $attrs['product_price']    ?? '';
            $tone     = $attrs['tone']             ?? 'friendly';
            $goal     = $attrs['goal']             ?? 'traffic';
            $audience = $attrs['audience']         ?? 'người dùng chung';
            $link     = $attrs['tracking_url']     ?? '';
            $count    = (int) ($attrs['variant_count'] ?? 3);

            $toneGuide = match ($tone) {
                'hype'         => 'Dùng ngôn từ hype, cảm xúc, nhiều emoji.',
                'professional' => 'Dùng ngôn từ lịch sự, chuyên nghiệp.',
                'minimalist'   => 'Ngắn gọn, súc tích, không emoji.',
                default        => 'Thân thiện, gần gũi, tự nhiên.',
            };

            return <<<PROMPT
            Bạn là chuyên gia viết content marketing affiliate người Việt.

            Nhiệm vụ: Viết {$count} phiên bản bài đăng Facebook (mỗi phiên bản cách nhau bằng dấu ---) để quảng bá "{$product}"{$this->priceHint($price)}.
            Mục tiêu chiến dịch: {$goal}.
            Đối tượng: {$audience}.
            Giọng văn: {$toneGuide}
            Luôn kết thúc bằng link: {$link}

            Trả về đúng {$count} phiên bản, KHÔNG thêm tiêu đề hay đánh số phiên bản.
            PROMPT;
        });

        $this->register('tiktok_caption_v1', 'TikTok caption + hook', ['text'], function (array $attrs): string {
            $product  = $attrs['product_title']   ?? 'sản phẩm';
            $price    = $attrs['product_price']    ?? '';
            $tone     = $attrs['tone']             ?? 'hype';
            $link     = $attrs['tracking_url']     ?? '';
            $count    = (int) ($attrs['variant_count'] ?? 3);

            return <<<PROMPT
            Viết {$count} caption TikTok affiliate cho "{$product}"{$this->priceHint($price)}.
            Giọng văn: {$tone}. Mỗi caption bao gồm:
            1. Hook mở đầu giật tít (1–2 câu).
            2. Nội dung ngắn (3–5 câu).
            3. CTA + link: {$link}
            4. Hashtags (5–8 tags).

            Phân cách mỗi phiên bản bằng ---.
            PROMPT;
        });

        $this->register('hashtags_pack_v1', 'Hashtags pack', ['text'], function (array $attrs): string {
            $product  = $attrs['product_title']   ?? 'sản phẩm';
            $platform = $attrs['platform']         ?? 'facebook';
            $count    = max(10, (int) ($attrs['variant_count'] ?? 20));

            return "Tạo {$count} hashtag phù hợp {$platform} cho sản phẩm \"{$product}\". Trả về mỗi hashtag trên một dòng.";
        });
    }

    /**
     * @param  list<string> $types  Supported generation types (text|image|video)
     */
    public function register(string $id, string $description, array $types, callable $renderer): void
    {
        $this->templates[$id] = compact('description', 'types', 'renderer');
    }

    /**
     * Render a template with the given attributes.
     *
     * @throws InvalidArgumentException
     */
    public function render(string $templateId, array $attributes): string
    {
        if (! isset($this->templates[$templateId])) {
            throw new InvalidArgumentException("Unknown prompt template: {$templateId}");
        }

        return ($this->templates[$templateId]['renderer'])($attributes);
    }

    /**
     * Check if a template ID is registered and supports the given type.
     */
    public function supports(string $templateId, string $type): bool
    {
        return isset($this->templates[$templateId])
            && in_array($type, $this->templates[$templateId]['types'], true);
    }

    /**
     * Return all template IDs that support a given type.
     *
     * @return list<string>
     */
    public function listFor(string $type): array
    {
        return array_keys(array_filter(
            $this->templates,
            fn ($t) => in_array($type, $t['types'], true)
        ));
    }

    private function priceHint(string $price): string
    {
        return $price ? " (giá {$price})" : '';
    }
}
