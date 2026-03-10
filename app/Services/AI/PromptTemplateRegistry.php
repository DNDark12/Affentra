<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Code-based registry of prompt templates.
 *
 * Each template is a callable that receives $attributes and returns a
 * fully-rendered string ready to be sent to the LLM/media provider.
 */
class PromptTemplateRegistry
{
    /**
     * @var array<string, array{description: string, types: list<string>, default_type: string, platform: string, renderer: callable}>
     */
    private array $templates = [];

    public function __construct()
    {
        $this->registerBuiltinTemplates();
    }

    private function registerBuiltinTemplates(): void
    {
        /**
         * TEXT: Facebook post
         */
        $this->register('fb_post', 'Facebook post (short/long)', ['text'], function (array $attrs): string {
            $product    = $this->stringAttr($attrs, 'product_title', 'product');
            $price      = $this->stringAttr($attrs, 'product_price');
            $tone       = $this->stringAttr($attrs, 'tone', 'friendly');
            $goal       = $this->stringAttr($attrs, 'goal', 'traffic');
            $audience   = $this->stringAttr($attrs, 'audience', 'general audience');
            $link       = $this->stringAttr($attrs, 'tracking_url');
            $ctaText    = $this->stringAttr($attrs, 'cta_text');
            $usp        = $this->stringAttr($attrs, 'usp');
            $offers     = $this->stringAttr($attrs, 'offers');
            $expiration = $this->stringAttr($attrs, 'expiration');
            $length     = $this->stringAttr($attrs, 'length', 'medium');

            return $this->joinLines([
                "Product Description Context:",
                '- Product Name: "' . $product . '"' . $this->priceHint($price),
                $usp !== '' ? '- Unique Selling Points (USP): ' . $usp : '',
                $offers !== '' ? '- Current Promotions/Offers: ' . $offers : '',
                $expiration !== '' ? '- Urgent Expiration/Deal ends: ' . $expiration : '',
                '- Goal: ' . $goal,
                '- Target Audience: ' . $audience,
                '- Tone of Voice: ' . $this->toneGuide($tone, 'text'),
                '- Content Length: ' . $this->lengthGuide($length),
                $link !== '' ? '- CTA Link: ' . $link : '',
                $ctaText !== '' ? '- CTA Phrase: ' . $ctaText : '',
                '',
                'Content Requirements:',
                '- Start with a strong, scroll-stopping hook.',
                '- Highlight 1-3 clear benefits.',
                '- End with a compelling call to action' . ($ctaText !== '' ? " using: \"{$ctaText}\"" : '') . '.',
                '- Maintain a natural, organic social media post feel.',
            ]);
        }, 'facebook');

        /**
         * TEXT: TikTok caption
         */
        $this->register('tiktok_caption', 'TikTok caption + hook', ['text'], function (array $attrs): string {
            $product    = $this->stringAttr($attrs, 'product_title', 'product');
            $price      = $this->stringAttr($attrs, 'product_price');
            $tone       = $this->stringAttr($attrs, 'tone', 'hype');
            $link       = $this->stringAttr($attrs, 'tracking_url');
            $ctaText    = $this->stringAttr($attrs, 'cta_text');
            $usp        = $this->stringAttr($attrs, 'usp');
            $offers     = $this->stringAttr($attrs, 'offers');
            $expiration = $this->stringAttr($attrs, 'expiration');

            return $this->joinLines([
                "Create a TikTok caption for product \"{$product}\"" . $this->priceHint($price) . '.',
                $usp !== '' ? '- Key Highlights/USP: ' . $usp : '',
                $offers !== '' ? '- Limited Time Offers: ' . $offers : '',
                $expiration !== '' ? '- Deadline/Urgency: ' . $expiration : '',
                '- Tone: ' . $this->toneGuide($tone, 'text'),
                $link !== '' ? '- CTA Link: ' . $link : '',
                $ctaText !== '' ? '- CTA Phrase: ' . $ctaText : '',
                '',
                'Requirements:',
                '- Capture attention in the first 3 words.',
                '- High energy, fast-paced language.',
                '- Clear call to action' . ($ctaText !== '' ? " using: \"{$ctaText}\"" : '') . '.',
                '- Include 5-8 relevant trending hashtags.',
            ]);
        }, 'tiktok');

        /**
         * TEXT: Carousel ad copy
         */
        $this->register('carousel_ad_copy', 'Carousel ad copy (Facebook)', ['text'], function (array $attrs): string {
            $product    = $this->stringAttr($attrs, 'product_title', 'product');
            $price      = $this->stringAttr($attrs, 'product_price');
            $tone       = $this->stringAttr($attrs, 'tone', 'friendly');
            $goal       = $this->stringAttr($attrs, 'goal', 'conversion');
            $audience   = $this->stringAttr($attrs, 'audience', 'general consumers');
            $usp        = $this->stringAttr($attrs, 'usp');
            $offers     = $this->stringAttr($attrs, 'offers');
            $expiration = $this->stringAttr($attrs, 'expiration');
            $link       = $this->stringAttr($attrs, 'tracking_url');
            $ctaText    = $this->stringAttr($attrs, 'cta_text', 'Shop now');

            return $this->joinLines([
                "Create a Facebook Carousel Ad copy for \"{$product}\"" . $this->priceHint($price) . '.',
                '- Campaign Goal: ' . $goal,
                '- Target Audience: ' . $audience,
                '- Tone: ' . $this->toneGuide($tone, 'text'),
                $usp !== '' ? '- USP: ' . $usp : '',
                $offers !== '' ? '- Exclusive Offers: ' . $offers : '',
                $expiration !== '' ? '- Validity/Expiration: ' . $expiration : '',
                $link !== '' ? '- CTA Link: ' . $link : '',
                '',
                'Ad Structure:',
                'Primary Text: [Compelling intro text]',
                'Card Headlines: [3 distinct card headlines]',
                'CTA Button: [Standard CTA text]',
            ]);
        }, 'facebook');

        /**
         * TEXT: Shopee title
         */
        $this->register('shopee_title', 'Shopee optimized product title', ['text'], function (array $attrs): string {
            $product  = $this->stringAttr($attrs, 'product_title', 'product');
            $price    = $this->stringAttr($attrs, 'product_price');
            $usp      = $this->stringAttr($attrs, 'usp');
            $offers   = $this->stringAttr($attrs, 'offers');
            $ctaText  = $this->stringAttr($attrs, 'cta_text');

            return $this->joinLines([
                "Create a Shopee optimized title for \"{$product}\"" . $this->priceHint($price) . '.',
                $usp !== '' ? '- Leverage USP: ' . $usp : '',
                $offers !== '' ? '- Mention Promotions: ' . $offers : '',
                $ctaText !== '' ? '- Preferred CTA style keywords: ' . $ctaText : '',
                '- Requirement: 55-120 characters, include high-volume keywords, no spammy symbols.',
            ]);
        }, 'shopee');

        /**
         * TEXT: SEO description
         */
        $this->register('seo_description', 'SEO meta/product description', ['text'], function (array $attrs): string {
            $product  = $this->stringAttr($attrs, 'product_title', 'product');
            $headline = $this->stringAttr($attrs, 'headline');
            $price    = $this->stringAttr($attrs, 'product_price');
            $usp      = $this->stringAttr($attrs, 'usp');
            $tone     = $this->stringAttr($attrs, 'tone', 'professional');
            $audience = $this->stringAttr($attrs, 'audience', 'online shoppers');

            return $this->joinLines([
                "Create an SEO/Meta description for \"{$product}\"" . $this->priceHint($price) . '.',
                $headline !== '' ? '- Focus on headline: ' . $headline : '',
                $usp !== '' ? '- Strategic USP focus: ' . $usp : '',
                '- Target Audience: ' . $audience,
                '- Length: 120-170 characters.',
            ]);
        }, 'generic');

        /**
         * TEXT: Hashtag pack
         */
        $this->register('hashtags_pack', 'Hashtags pack', ['text'], function (array $attrs): string {
            $product  = $this->stringAttr($attrs, 'product_title', 'product');
            $platform = $this->stringAttr($attrs, 'platform', 'facebook');
            $count    = $this->intAttr($attrs, 'variant_count', 20, 10, 50);

            return $this->joinLines([
                "Task: Generate {$count} relevant hashtags for {$platform} to promote \"{$product}\".",
                '',
                'Requirements:',
                '- Mix of broad, niche, and purchase-intent hashtags.',
                '- Optimized for high reach and engagement.',
                '- No duplicates, no explanations.',
                '',
                'Format:',
                '- Single hashtag per line, no numbering.',
            ]);
        }, 'generic');

        /**
         * IMAGE: Facebook ad image
         */
        $this->register('fb_post_image', 'Facebook ad image', ['image'], function (array $attrs): string {
            $product      = $this->stringAttr($attrs, 'product_title', 'product');
            $usp          = $this->stringAttr($attrs, 'usp');
            $tone         = $this->stringAttr($attrs, 'tone', 'clean, vibrant, premium');
            $audience     = $this->stringAttr($attrs, 'audience', 'mass market');
            $goal         = $this->stringAttr($attrs, 'goal', 'conversion');
            $headline     = $this->stringAttr($attrs, 'headline');
            $price        = $this->stringAttr($attrs, 'product_price');
            $aspectRatio  = $this->stringAttr($attrs, 'aspect_ratio', '4:5');
            $visualStyle  = $this->stringAttr($attrs, 'visual_style', 'Studio-quality commercial photography');

            return $this->joinLines([
                "Create a high-converting social ad image for the product \"{$product}\".",
                "Campaign goal: {$goal}.",
                "Target audience: {$audience}.",
                "Overall tone: {$tone}.",
                "Visual style: {$visualStyle}.",
                $usp !== '' ? "Key selling point: {$usp}." : '',
                '',
                'Composition requirements:',
                '- One clear hero product as the main subject.',
                '- Clean, premium layout with strong visual hierarchy.',
                '- Studio-quality lighting and sharp focus.',
                '- Background should support the product.',
                '- Leave clean negative space for overlays.',
                '',
                'Text handling:',
                $headline !== '' ? "- Allowed headline text: {$headline}" : '- Minimal or no embedded text.',
                $price !== '' ? "- Allowed price text: {$price}" : '',
                '',
                "Format: {$aspectRatio}, optimized for Facebook ads.",
            ]);
        }, 'facebook');

        /**
         * VIDEO: generic short ad
         */
        $this->register('short_video_ad', 'Short product ad video', ['video'], function (array $attrs): string {
            return $this->buildShortVideoPrompt($attrs, 'UGC-style commercial realism');
        }, 'generic');

        /**
         * VIDEO: product story format
         */
        $this->register('product_story_video', 'Product story video', ['video'], function (array $attrs): string {
            $style = $this->stringAttr($attrs, 'visual_style', 'cinematic product storytelling');

            return $this->buildShortVideoPrompt($attrs, $style, [
                '- Scene 1: problem context in daily life (first 2 seconds).',
                '- Scene 2: introduce the product as the turning point.',
                '- Scene 3: show transformation with before/after contrast.',
                '- Scene 4: close with confident CTA frame and packshot.',
            ]);
        }, 'generic');

        /**
         * VIDEO: UGC review style
         */
        $this->register('ugc_review_video', 'UGC review-style video ad', ['video'], function (array $attrs): string {
            $style = $this->stringAttr($attrs, 'visual_style', 'authentic UGC review, handheld but stable');

            return $this->buildShortVideoPrompt($attrs, $style, [
                '- Scene 1: creator-style hook and quick product reveal.',
                '- Scene 2: first-person usage demo with natural reactions.',
                '- Scene 3: concrete benefit/result proof shot.',
                '- Scene 4: CTA frame with product close-up.',
            ], [
                '- Include natural face/hand movement consistency.',
                '- Keep pacing energetic but believable like real UGC.',
            ]);
        }, 'tiktok');

        // Media enrichment for fb_post when user requests video in addition to text.
        $this->register('fb_post_video', 'Facebook post video enrichment', ['video'], function (array $attrs): string {
            return $this->buildShortVideoPrompt($attrs, 'clean social commercial realism');
        }, 'facebook');
    }

    /**
     * @param  list<string> $types
     */
    public function register(
        string $id,
        string $description,
        array $types,
        callable $renderer,
        string $platform = 'generic',
    ): void {
        $defaultType = $types[0] ?? 'text';
        $this->templates[$id] = [
            'description'  => $description,
            'types'        => array_values($types),
            'default_type' => $defaultType,
            'platform'     => $platform,
            'renderer'     => $renderer,
        ];
    }

    public function get(string $templateId): array
    {
        if (! isset($this->templates[$templateId])) {
            throw new InvalidArgumentException("Unknown prompt template: {$templateId}");
        }

        return $this->templates[$templateId];
    }

    public function render(string $templateId, array $attributes, ?string $modality = null): string
    {
        $customPrompt = $this->stringAttr($attributes, 'custom_prompt');
        if ($customPrompt !== '') {
            return $customPrompt;
        }

        $template = $this->get($templateId);
        $resolvedModality = $modality ?? $template['default_type'];

        // 1. Try specialized template first (e.g., fb_post_image)
        $specializedId = "{$templateId}_{$resolvedModality}";
        if (isset($this->templates[$specializedId])) {
            return ($this->templates[$specializedId]['renderer'])($attributes);
        }

        // 2. Try primary template if its metadata strictly supports the modality
        if (in_array($resolvedModality, $template['types'], true)) {
            return ($template['renderer'])($attributes);
        }

        // 3. Modality-specific fallback builders for cross-modality (Text -> Image/Video)
        if ($resolvedModality === 'image') {
            return $this->buildFallbackImagePrompt($attributes);
        }

        if ($resolvedModality === 'video') {
            return $this->buildFallbackVideoPrompt($attributes);
        }

        throw new InvalidArgumentException("Template [{$templateId}] does not support modality [{$resolvedModality}] and no fallback exists.");
    }

    public function supports(string $templateId, string $type): bool
    {
        return isset($this->templates[$templateId])
            && in_array($type, $this->templates[$templateId]['types'], true);
    }

    /**
     * @return list<string>
     */
    public function listFor(string $type): array
    {
        return array_values(array_keys(array_filter(
            $this->templates,
            fn (array $template) => in_array($type, $template['types'], true)
        )));
    }

    private function stringAttr(array $attrs, string $key, string $default = ''): string
    {
        $value = $attrs[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function intAttr(array $attrs, string $key, int $default, int $min, int $max): int
    {
        $value = (int) ($attrs[$key] ?? $default);

        return max($min, min($max, $value));
    }

    private function boolAttr(array $attrs, string $key): bool
    {
        return (bool) ($attrs[$key] ?? false);
    }

    private function maxVariants(): int
    {
        return max(1, (int) config('ai.features.max_variants', 5));
    }

    private function priceHint(string $price): string
    {
        return $price !== '' ? " (priced at {$price})" : '';
    }

    /**
     * @param  list<string> $lines
     */
    private function joinLines(array $lines): string
    {
        $filtered = array_values(array_filter(
            array_map(
                fn ($line) => is_string($line) ? rtrim($line) : '',
                $lines
            ),
            fn (string $line) => $line !== ''
        ));

        return implode("\n", $filtered);
    }

    private function toneGuide(string $tone, string $mode): string
    {
        if ($mode === 'text') {
            return match ($tone) {
                'hype'         => 'High energy, emotional, fast-paced, social-media oriented.',
                'professional' => 'Polite, clear, authoritative, and trustworthy.',
                'minimalist'   => 'Concise, direct, fewer exclamation marks.',
                'friendly'     => 'Warm, approachable, conversational, and natural.',
                default        => $tone,
            };
        }

        return $tone;
    }

    private function lengthGuide(string $length): string
    {
        return match ($length) {
            'short'  => 'Approximately 40-80 words.',
            'long'   => 'Approximately 120-220 words.',
            default  => 'Approximately 80-140 words.',
        };
    }

    /**
     * @param  list<string> $videoStructure
     * @param  list<string> $extraDirection
     */
    private function buildShortVideoPrompt(
        array $attrs,
        string $defaultVisualStyle,
        array $videoStructure = [
            '- Scene 1: strong visual hook in the first 2 seconds.',
            '- Scene 2: show the product clearly in use or in context.',
            '- Scene 3: emphasize the main benefit or transformation.',
            '- Scene 4: end with a clear CTA frame.',
        ],
        array $extraDirection = [],
    ): string {
        $product      = $this->stringAttr($attrs, 'product_title', 'product');
        $price        = $this->stringAttr($attrs, 'product_price');
        $usp          = $this->stringAttr($attrs, 'usp');
        $tone         = $this->stringAttr($attrs, 'tone', 'dynamic, persuasive, modern');
        $audience     = $this->stringAttr($attrs, 'audience', 'online shoppers');
        $goal         = $this->stringAttr($attrs, 'goal', 'conversion');
        $durationSec  = $this->intAttr($attrs, 'duration_sec', $this->intAttr($attrs, 'duration', 10, 4, 30), 4, 30);
        $aspectRatio  = $this->stringAttr($attrs, 'aspect_ratio', '9:16');
        $ctaText      = $this->stringAttr($attrs, 'cta_text', 'Shop now');
        $visualStyle  = $this->stringAttr($attrs, 'visual_style', $defaultVisualStyle);
        $offers       = $this->stringAttr($attrs, 'offers');
        $expiration   = $this->stringAttr($attrs, 'expiration');

        return $this->joinLines([
            "Create a {$durationSec}-second vertical product ad video for \"{$product}\"" . ($price !== '' ? " (priced at {$price})" : "") . ".",
            "Campaign goal: {$goal}.",
            "Target audience: {$audience}.",
            "Overall tone: {$tone}.",
            "Visual style: {$visualStyle}.",
            $usp !== '' ? "Core message: {$usp}." : '',
            $offers !== '' ? "Current Promotions: {$offers}." : '',
            $expiration !== '' ? "Deadline/Urgency: {$expiration}." : '',
            '',
            'Video structure:',
            ...$videoStructure,
            '',
            'Direction:',
            '- Fast-paced but clean editing.',
            '- Clear product visibility in every important shot.',
            '- Realistic motion and consistent product appearance across scenes.',
            '- Natural camera movement.',
            '- Commercial-quality lighting.',
            '- No messy background.',
            ...$extraDirection,
            '',
            'Text and overlays:',
            "- Final CTA text: {$ctaText}.",
            '- Keep on-screen text minimal and readable.',
            '',
            "Format: {$aspectRatio}, optimized for short-form social video.",
        ]);
    }

    /**
     * Fallback high-quality visual brief builder for requesting image output from a text preset.
     */
    private function buildFallbackImagePrompt(array $attrs): string
    {
        $product      = $this->stringAttr($attrs, 'product_title', 'product');
        $price        = $this->stringAttr($attrs, 'product_price');
        $usp          = $this->stringAttr($attrs, 'usp');
        $tone         = $this->stringAttr($attrs, 'tone', 'commercial, premium');
        $audience     = $this->stringAttr($attrs, 'audience', 'general consumers');
        $goal         = $this->stringAttr($attrs, 'goal', 'conversion');
        $headline     = $this->stringAttr($attrs, 'headline');
        $offers       = $this->stringAttr($attrs, 'offers');
        $expiration   = $this->stringAttr($attrs, 'expiration');
        $aspectRatio  = $this->stringAttr($attrs, 'aspect_ratio', '1:1');
        $visualStyle  = $this->stringAttr($attrs, 'visual_style', 'High-quality studio commercial photography');

        return $this->joinLines([
            "Create a high-converting social ad image for the product \"{$product}\"" . $this->priceHint($price) . ".",
            "Campaign goal: {$goal}.",
            "Target audience: {$audience}.",
            "Overall tone: {$tone}.",
            "Visual style: {$visualStyle}.",
            $usp !== '' ? "Core message/USP: {$usp}." : '',
            $offers !== '' ? "Current Promotions: {$offers}." : '',
            $expiration !== '' ? "Deadline/Urgency: {$expiration}." : '',
            '',
            'Composition requirements:',
            '- One clear hero product as the main subject.',
            '- Clean, premium layout with strong visual hierarchy.',
            '- Studio-quality lighting and sharp focus.',
            '- Background should support the product and fit the commercial tone.',
            '- Leave clean negative space for textual overlays.',
            '',
            'Text handling:',
            $headline !== '' ? "- Allowed headline text: {$headline}" : '- Minimal or no embedded text.',
            '',
            "Format: {$aspectRatio}, optimized for social media ads.",
        ]);
    }

    /**
     * Fallback video brief builder for requesting video output from a text preset.
     */
    private function buildFallbackVideoPrompt(array $attrs): string
    {
        return $this->buildShortVideoPrompt($attrs, 'High-energy commercial realism');
    }
}
