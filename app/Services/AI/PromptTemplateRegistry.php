<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Structured Brief Prompt Template Registry
 *
 * Each template uses a structured framework:
 *   CONTEXT → OBJECTIVE → TARGET AUDIENCE → CONSTRAINTS → OUTPUT FORMAT
 *
 * This replaces the old flat attribute-list approach with a framework
 * that gives AI models clear priority signals and structured reasoning.
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
        // ─── TEXT TEMPLATES ────────────────────────────────────────────────────

        /**
         * Facebook Post — organic / boosted
         */
        $this->register('fb_post', 'Facebook post (short/long)', ['text'], function (array $attrs): string {
            $product    = $this->stringAttr($attrs, 'product_title', 'product');
            $price      = $this->stringAttr($attrs, 'product_price');
            $tone       = $this->stringAttr($attrs, 'tone', 'friendly');
            $goal       = $this->stringAttr($attrs, 'goal', 'traffic');
            $audience   = $this->stringAttr($attrs, 'audience', 'general audience');
            $link       = $this->stringAttr($attrs, 'tracking_url');
            $usp        = $this->stringAttr($attrs, 'usp');
            $offers     = $this->stringAttr($attrs, 'offers');
            $expiration = $this->stringAttr($attrs, 'expiration');
            $policy     = $this->stringAttr($attrs, 'policy');
            $length     = $this->stringAttr($attrs, 'length', 'medium');
            $framework  = $this->selectPersuasionFramework($goal);
            $imgRef     = $this->imageReferenceBlock($attrs, 'text');

            return $this->joinLines([
                '## CONTEXT',
                "Sản phẩm: \"{$product}\"" . $this->priceHint($price),
                $usp !== '' ? "Điểm bán hàng độc đáo (USP): {$usp}" : '',
                $offers !== '' ? "Khuyến mãi đang chạy: {$offers}" : '',
                $expiration !== '' ? "Hạn chót/Khẩn cấp: {$expiration}" : '',
                $policy !== '' ? "Chính sách: {$policy}" : '',
                $imgRef,
                '',
                '## OBJECTIVE',
                "Mục tiêu chiến dịch: {$goal}",
                "Persuasion Framework: {$framework}",
                '',
                '## TARGET AUDIENCE',
                "Đối tượng: {$audience}",
                "Tone of Voice: {$this->toneGuide($tone)}",
                '',
                '## CONSTRAINTS',
                "Độ dài: {$this->lengthGuide($length)}",
                $link !== '' ? "CTA link (bắt buộc đặt ở cuối): {$link}" : '',
                '',
                '## OUTPUT FORMAT',
                'Cấu trúc mỗi variant:',
                '',
                '**HOOK** (Dòng đầu tiên — phải xuất hiện trước "Xem thêm"):',
                '- Dùng pattern interrupt: câu hỏi gây tò mò, con số cụ thể, hoặc tuyên bố gây sốc.',
                '- Mục tiêu: khiến người đọc PHẢI bấm "Xem thêm".',
                '',
                '**BODY** (Phần thân bài):',
                "- Áp dụng framework {$framework}.",
                '- Nêu 1-3 lợi ích cụ thể, kèm bằng chứng.',
                '- Dùng ngôn ngữ tự nhiên, chân thực — như đang chia sẻ với bạn bè.',
                '- Ngắt dòng giữa các ý (mỗi ý 2-3 dòng, rồi xuống hàng).',
                '',
                '**CTA** (Kết bài):',
                '- Hành động cụ thể, rõ ràng.',
                $link !== '' ? '- Kèm link tracking ở cuối cùng.' : '',
            ]);
        }, 'facebook');

        /**
         * TikTok Caption
         */
        $this->register('tiktok_caption', 'TikTok caption + hook', ['text'], function (array $attrs): string {
            $product    = $this->stringAttr($attrs, 'product_title', 'product');
            $price      = $this->stringAttr($attrs, 'product_price');
            $tone       = $this->stringAttr($attrs, 'tone', 'hype');
            $link       = $this->stringAttr($attrs, 'tracking_url');
            $usp        = $this->stringAttr($attrs, 'usp');
            $offers     = $this->stringAttr($attrs, 'offers');
            $expiration = $this->stringAttr($attrs, 'expiration');
            $imgRef     = $this->imageReferenceBlock($attrs, 'text');

            return $this->joinLines([
                '## CONTEXT',
                "Sản phẩm: \"{$product}\"" . $this->priceHint($price),
                $usp !== '' ? "USP: {$usp}" : '',
                $offers !== '' ? "Deal đang chạy: {$offers}" : '',
                $expiration !== '' ? "Deadline: {$expiration}" : '',
                $imgRef,
                '',
                '## OBJECTIVE',
                'Tạo TikTok caption tối ưu cho engagement + click-through.',
                "Tone: {$this->toneGuide($tone)}",
                '',
                '## CONSTRAINTS',
                '- Tổng caption: 80-150 ký tự (không tính hashtags).',
                '- Hashtag: đúng 5 hashtag (2 broad + 2 niche + 1 trending). KHÔNG dùng #fyp #foryou.',
                $link !== '' ? "- CTA link: {$link}" : '',
                '',
                '## OUTPUT FORMAT',
                'Cấu trúc caption:',
                '',
                '**HOOK** (3 từ đầu tiên — quyết định sống còn):',
                '- Phải gây shock, tò mò, hoặc FOMO ngay lập tức.',
                '- Pattern: "POV: ...", "Đừng mua ... nếu chưa xem", "Cái này thay đổi ...".',
                '',
                '**BODY** (1-2 câu ngắn):',
                '- Viết ngôi thứ nhất (tôi/mình). Creator voice, không brand voice.',
                '- Kèm emoji nhưng không quá 3.',
                '',
                '**TAIL**:',
                '- Comment bait: câu hỏi hoặc "tag ai đó...".',
                '- Hashtags trên dòng riêng.',
            ]);
        }, 'tiktok');

        /**
         * Carousel Ad Copy
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
            $imgRef     = $this->imageReferenceBlock($attrs, 'text');

            return $this->joinLines([
                '## CONTEXT',
                "Sản phẩm: \"{$product}\"" . $this->priceHint($price),
                $usp !== '' ? "USP: {$usp}" : '',
                $offers !== '' ? "Khuyến mãi: {$offers}" : '',
                $expiration !== '' ? "Hạn: {$expiration}" : '',
                $imgRef,
                '',
                '## OBJECTIVE',
                "Tạo Facebook Carousel Ad copy. Mục tiêu: {$goal}.",
                "Audience: {$audience}. Tone: {$this->toneGuide($tone)}.",
                '',
                '## CONSTRAINTS',
                '- Primary Text: tối đa 125 ký tự (above-the-fold).',
                '- Mỗi Card Headline: tối đa 40 ký tự.',
                '- Card Description: tối đa 20 ký tự.',
                $link !== '' ? "- CTA link: {$link}" : '',
                '',
                '## OUTPUT FORMAT',
                'Mỗi variant theo cấu trúc:',
                '',
                '**Primary Text**: [Hook + value proposition dưới 125 ký tự]',
                '',
                '**Card 1 Headline**: [Lợi ích chính — thu hút click đầu tiên]',
                '**Card 2 Headline**: [Social proof hoặc tính năng nổi bật]',
                '**Card 3 Headline**: [Urgency/scarcity hoặc ưu đãi]',
                '',
                'Persuasion Arc: Card 1 (Curiosity) → Card 2 (Proof) → Card 3 (Action).',
            ]);
        }, 'facebook');

        /**
         * Shopee Product Title
         */
        $this->register('shopee_title', 'Shopee optimized product title', ['text'], function (array $attrs): string {
            $product  = $this->stringAttr($attrs, 'product_title', 'product');
            $price    = $this->stringAttr($attrs, 'product_price');
            $usp      = $this->stringAttr($attrs, 'usp');
            $offers   = $this->stringAttr($attrs, 'offers');
            $imgRef   = $this->imageReferenceBlock($attrs, 'text');

            return $this->joinLines([
                '## CONTEXT',
                "Sản phẩm gốc: \"{$product}\"" . $this->priceHint($price),
                $usp !== '' ? "USP: {$usp}" : '',
                $offers !== '' ? "Khuyến mãi: {$offers}" : '',
                $imgRef,
                '',
                '## OBJECTIVE',
                'Tạo tiêu đề Shopee tối ưu SEO — mục tiêu: top 10 kết quả tìm kiếm.',
                '',
                '## CONSTRAINTS',
                '- Độ dài: 55-120 ký tự (Shopee cắt ở 120).',
                '- 40 ký tự đầu: PHẢI chứa keyword quan trọng nhất.',
                '- KHÔNG dùng ký tự đặc biệt (★, ♥, 🔥) — Shopee phạt/bỏ qua.',
                '- KHÔNG lặp keyword — thuật toán coi là spam.',
                '- Ngôn ngữ tự nhiên, đọc được, không nhồi keyword.',
                '',
                '## OUTPUT FORMAT',
                'Title Formula: [Thương hiệu] + [Loại SP] + [Đặc điểm chính] + [Chất liệu/Công dụng] + [USP]',
                '',
                'Ví dụ format tốt:',
                '- "Serum Vitamin C 20% XYZ - Sáng Da Mờ Thâm - Chiết Xuất Chanh Tươi 30ml"',
                '- "Áo Thun Cotton 100% ABC - Thoáng Mát - Form Oversize Unisex"',
                '',
                'Mỗi variant là 1 dòng title duy nhất, không giải thích.',
            ]);
        }, 'shopee');

        /**
         * SEO Meta/Product Description
         */
        $this->register('seo_description', 'SEO meta/product description', ['text'], function (array $attrs): string {
            $product  = $this->stringAttr($attrs, 'product_title', 'product');
            $headline = $this->stringAttr($attrs, 'headline');
            $price    = $this->stringAttr($attrs, 'product_price');
            $usp      = $this->stringAttr($attrs, 'usp');
            $tone     = $this->stringAttr($attrs, 'tone', 'professional');
            $audience = $this->stringAttr($attrs, 'audience', 'online shoppers');
            $imgRef   = $this->imageReferenceBlock($attrs, 'text');

            return $this->joinLines([
                '## CONTEXT',
                "Sản phẩm: \"{$product}\"" . $this->priceHint($price),
                $headline !== '' ? "Focus keyword/headline: {$headline}" : '',
                $usp !== '' ? "USP: {$usp}" : '',
                $imgRef,
                '',
                '## OBJECTIVE',
                'Tạo SEO meta description tối ưu cho Google SERP CTR và AI Search (GEO).',
                "Target audience: {$audience}. Tone: {$this->toneGuide($tone)}.",
                '',
                '## CONSTRAINTS',
                '- Độ dài: 120-160 ký tự (Google hiển thị tối đa 160).',
                '- PHẢI chứa primary keyword trong 60 ký tự đầu.',
                '- Kết thúc bằng trigger word tăng CTR: "Xem ngay", "Tìm hiểu thêm", "Mua với giá tốt nhất".',
                '',
                '## OUTPUT FORMAT',
                'Cấu trúc meta description:',
                '[Keyword-rich opening] + [Core benefit/USP] + [CTR trigger word]',
                '',
                'GEO Optimization: Viết THÊM 1 câu Q&A format dưới mỗi meta description:',
                'Q: [Câu hỏi mà người mua thường search]',
                'A: [Câu trả lời ngắn gọn, có entity/fact cụ thể — để AI search có thể trích dẫn]',
            ]);
        }, 'generic');

        /**
         * Hashtags Pack
         */
        $this->register('hashtags_pack', 'Hashtags pack', ['text'], function (array $attrs): string {
            $product  = $this->stringAttr($attrs, 'product_title', 'product');
            $platform = $this->stringAttr($attrs, 'platform', 'facebook');
            $count    = $this->intAttr($attrs, 'variant_count', 20, 10, 50);

            return $this->joinLines([
                '## CONTEXT',
                "Sản phẩm: \"{$product}\"",
                "Platform: {$platform}",
                '',
                '## OBJECTIVE',
                "Tạo {$count} hashtag tối ưu reach + engagement cho {$platform}.",
                '',
                '## CONSTRAINTS',
                '- Phân loại rõ 3 tier:',
                '  Tier 1 (Broad reach, 1M+ posts): ~30% tổng số.',
                '  Tier 2 (Niche, 10K-1M posts): ~50% tổng số.',
                '  Tier 3 (Purchase-intent/Long-tail, <10K posts): ~20% tổng số.',
                '- KHÔNG dùng #fyp #foryou #viral (noise, không signal).',
                '- KHÔNG trùng lặp.',
                '- PHẢI include ít nhất 3 hashtag tiếng Việt.',
                '',
                '## OUTPUT FORMAT',
                'Mỗi hashtag 1 dòng, không đánh số, không giải thích.',
                'Nhóm theo tier với header: [BROAD], [NICHE], [PURCHASE INTENT].',
            ]);
        }, 'generic');

        // ─── IMAGE TEMPLATES ───────────────────────────────────────────────────

        /**
         * Facebook Ad Image
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
            $imgRef       = $this->imageReferenceBlock($attrs, 'image');

            return $this->joinLines([
                '## CONTEXT',
                "Product: \"{$product}\"" . $this->priceHint($price),
                $usp !== '' ? "Key selling point: {$usp}" : '',
                "Campaign goal: {$goal}. Target audience: {$audience}.",
                $imgRef,
                '',
                '## VISUAL DIRECTION',
                "Style: {$visualStyle}. Tone: {$tone}.",
                '',
                'Composition:',
                '- ONE clear hero product — the undeniable focal point. Product occupies 40-60% of frame.',
                '- Clean, premium layout with strong visual hierarchy.',
                '- Studio-quality lighting with controlled shadows.',
                '- Background supports product context (lifestyle or neutral) — never competes.',
                '- Negative space preserved for potential text overlay zones (top 20%, bottom 20%).',
                '',
                'Color Psychology:',
                '- Warm tones for lifestyle/beauty. Cool tones for tech. Vibrant for fashion/food.',
                '- Ensure product colors are true-to-life and saturated.',
                '',
                'Text handling:',
                $headline !== '' ? "- Allowed headline: \"{$headline}\" (clean, sans-serif, high contrast)" : '- NO embedded text. Clean product photography only.',
                $price !== '' ? "- Price tag: {$price} (if visually appropriate)" : '',
                '',
                "## FORMAT: {$aspectRatio}, optimized for Facebook Feed/Ads.",
            ]);
        }, 'facebook');

        // ─── VIDEO TEMPLATES ───────────────────────────────────────────────────

        /**
         * Short Video Ad — generic
         */
        $this->register('short_video_ad', 'Short product ad video', ['video'], function (array $attrs): string {
            return $this->buildStructuredVideoPrompt($attrs, 'UGC-style commercial realism');
        }, 'generic');

        /**
         * Product Story Video
         */
        $this->register('product_story_video', 'Product story video', ['video'], function (array $attrs): string {
            $style = $this->stringAttr($attrs, 'visual_style', 'cinematic product storytelling');

            return $this->buildStructuredVideoPrompt($attrs, $style, [
                '- Scene 1 (0-2s): PROBLEM — relatable daily life frustration. Viewer thinks "that\'s me".',
                '- Scene 2 (2-5s): REVEAL — product enters as the turning point. Clean product hero shot.',
                '- Scene 3 (5-8s): TRANSFORMATION — before/after contrast with emotional payoff.',
                '- Scene 4 (8-10s): CTA — confident closing frame with product packshot and call-to-action.',
            ]);
        }, 'generic');

        /**
         * UGC Review Video
         */
        $this->register('ugc_review_video', 'UGC review-style video ad', ['video'], function (array $attrs): string {
            $style = $this->stringAttr($attrs, 'visual_style', 'authentic UGC review, handheld but stable');

            return $this->buildStructuredVideoPrompt($attrs, $style, [
                '- Scene 1 (0-2s): HOOK — creator-style "wait till you see this" and quick product reveal.',
                '- Scene 2 (2-5s): DEMO — first-person usage with natural, genuine reactions.',
                '- Scene 3 (5-8s): PROOF — concrete benefit/result shown (close-up, comparison).',
                '- Scene 4 (8-10s): CTA — product close-up with enthusiastic recommendation.',
            ], [
                '- Natural face/hand movement consistency throughout all scenes.',
                '- Pacing: energetic but believable — real UGC, not scripted commercial.',
                '- Imperfect framing is OK — adds authenticity.',
            ]);
        }, 'tiktok');

        // Media enrichment for fb_post when user requests video in addition to text.
        $this->register('fb_post_video', 'Facebook post video enrichment', ['video'], function (array $attrs): string {
            return $this->buildStructuredVideoPrompt($attrs, 'clean social commercial realism');
        }, 'facebook');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════════════════

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

    private function priceHint(string $price): string
    {
        return $price !== '' ? " (Giá: {$price})" : '';
    }

    /**
     * @param  list<string> $lines
     */
    private function joinLines(array $lines): string
    {
        // Filter empty lines but preserve intentional blank lines ('')
        $result = [];
        foreach ($lines as $line) {
            if (!is_string($line)) continue;
            $trimmed = rtrim($line);
            // Keep empty strings as blank separator lines
            if ($trimmed === '' && !empty($result)) {
                $result[] = '';
                continue;
            }
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return implode("\n", $result);
    }

    // ─── PERSUASION & CONTENT HELPERS ──────────────────────────────────────────

    /**
     * Select the optimal persuasion framework based on campaign goal.
     */
    private function selectPersuasionFramework(string $goal): string
    {
        return match ($goal) {
            'conversion', 'sales' => 'PAS (Problem → Agitate → Solve)',
            'traffic'             => 'AIDA (Attention → Interest → Desire → Action)',
            'awareness', 'brand'  => 'BAF (Before → After → Bridge)',
            'engagement'          => 'Story Arc (Hook → Conflict → Resolution → CTA)',
            default               => 'AIDA (Attention → Interest → Desire → Action)',
        };
    }

    /**
     * Generate image reference instructions based on attached product images.
     */
    private function imageReferenceBlock(array $attrs, string $modality): string
    {
        $images = $attrs['images'] ?? [];
        if (empty($images) || !is_array($images)) {
            return '';
        }

        $count = count($images);
        $noun  = $count === 1 ? '1 ảnh sản phẩm tham khảo' : "{$count} ảnh sản phẩm tham khảo";

        return match ($modality) {
            'text' => implode("\n", [
                '',
                "## ẢNH THAM KHẢO ({$noun} đính kèm)",
                "Đã đính kèm {$noun} để bạn hiểu rõ sản phẩm.",
                '- Quan sát kỹ: màu sắc, hình dáng, bao bì, kích thước, chất liệu thực tế.',
                '- Sử dụng chi tiết từ ảnh để viết mô tả chính xác, cụ thể.',
                '- Nêu các đặc điểm NHÌN THẤY ĐƯỢC trong ảnh (ví dụ: "chai thủy tinh trong suốt", "bao bì màu hồng pastel").',
                '- KHÔNG bịa đặt chi tiết không có trong ảnh.',
            ]),
            'image' => implode("\n", [
                '',
                "## REFERENCE IMAGES ({$noun} attached)",
                "Use the {$count} attached product image(s) as the primary visual reference.",
                '- Match the EXACT product appearance: shape, color, packaging, branding, labels.',
                '- The generated image must feature THIS specific product — not a generic version.',
                '- Maintain accurate proportions and real-world scale.',
                '- You may enhance lighting, background, and composition — but the product itself must be faithful to the reference.',
            ]),
            'video' => implode("\n", [
                '',
                "## REFERENCE IMAGES ({$noun} attached)",
                "Use the {$count} attached product image(s) as visual reference for the video.",
                '- The product in every scene must match the reference: shape, color, packaging, branding.',
                '- Maintain visual consistency of the product across ALL scenes.',
                '- Use the reference to determine realistic product scale and proportions.',
                '- You may create dynamic angles and contexts — but the product identity must remain unmistakably the same.',
            ]),
            default => '',
        };
    }

    private function toneGuide(string $tone): string
    {
        return match ($tone) {
            'hype'         => 'Năng lượng cao, cảm xúc, nhanh, social-media native. Dùng emoji chiến lược.',
            'professional' => 'Lịch sự, rõ ràng, đáng tin cậy, uy tín. Hạn chế emoji.',
            'minimalist'   => 'Ngắn gọn, trật tự, ít dấu chấm than. Elegant.',
            'friendly'     => 'Ấm áp, gần gũi, hội thoại tự nhiên. Như nói chuyện với bạn thân.',
            default        => $tone,
        };
    }

    private function lengthGuide(string $length): string
    {
        return match ($length) {
            'short'  => '40-80 từ. Ngắn gọn, mỗi từ đều quan trọng.',
            'long'   => '120-220 từ. Chi tiết, storytelling, nhiều social proof.',
            default  => '80-140 từ. Cân bằng giữa thông tin và engagement.',
        };
    }

    // ─── VIDEO PROMPT BUILDER ─────────────────────────────────────────────────

    /**
     * @param  list<string> $videoStructure
     * @param  list<string> $extraDirection
     */
    private function buildStructuredVideoPrompt(
        array $attrs,
        string $defaultVisualStyle,
        array $videoStructure = [],
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
        $visualStyle  = $this->stringAttr($attrs, 'visual_style', $defaultVisualStyle);
        $offers       = $this->stringAttr($attrs, 'offers');
        $expiration   = $this->stringAttr($attrs, 'expiration');
        $imgRef       = $this->imageReferenceBlock($attrs, 'video');

        if (empty($videoStructure)) {
            $videoStructure = $this->buildAdaptiveScenes($durationSec);
        }

        return $this->joinLines([
            '## CONTEXT',
            "Product: \"{$product}\"" . ($price !== '' ? " (Giá: {$price})" : ""),
            $usp !== '' ? "Core message/USP: {$usp}" : '',
            $offers !== '' ? "Current promotions: {$offers}" : '',
            $expiration !== '' ? "Deadline/Urgency: {$expiration}" : '',
            "Campaign goal: {$goal}. Target audience: {$audience}.",
            $imgRef,
            '',
            '## VISUAL DIRECTION',
            "Duration: {$durationSec} seconds. Format: {$aspectRatio} (vertical, short-form social).",
            "Style: {$visualStyle}. Tone: {$tone}.",
            '',
            '## SCENE BREAKDOWN',
            ...$videoStructure,
            '',
            '## PRODUCTION STANDARDS',
            '- Fast-paced but clean editing — no jarring cuts.',
            '- Product must be clearly visible and consistent across all scenes.',
            '- Realistic motion and physics. Natural camera movement.',
            '- Commercial-quality lighting. No messy backgrounds.',
            '- Color grading should be consistent and platform-appropriate.',
            '- NO text, NO captions, NO overlays — pure visual storytelling only.',
            ...$extraDirection,
        ]);
    }

    /**
     * Build adaptive scene breakdown based on video duration.
     * Short videos (≤6s) get 3 scenes, longer videos get 4 scenes.
     *
     * @return list<string>
     */
    private function buildAdaptiveScenes(int $duration): array
    {
        if ($duration <= 6) {
            // Short video: 3 scenes (Hook → Showcase+Benefit → Closing)
            $mid = max(2, (int) round($duration * 0.5));
            return [
                "- Scene 1 (0-2s): HOOK — strong visual hook that stops the scroll immediately.",
                "- Scene 2 (2-{$mid}s): SHOWCASE — product in use, highlighting main benefit visually.",
                "- Scene 3 ({$mid}-{$duration}s): CLOSING — product hero shot with confident framing.",
            ];
        }

        // Longer video: 4 scenes with proper distribution
        $s2End = (int) round($duration * 0.4);
        $s3End = (int) round($duration * 0.7);
        return [
            "- Scene 1 (0-2s): HOOK — strong visual hook that stops the scroll immediately.",
            "- Scene 2 (2-{$s2End}s): SHOWCASE — product clearly in use or context.",
            "- Scene 3 ({$s2End}-{$s3End}s): BENEFIT — the main benefit or transformation.",
            "- Scene 4 ({$s3End}-{$duration}s): CLOSING — product hero shot with confident framing.",
        ];
    }

    // ─── FALLBACK BUILDERS ────────────────────────────────────────────────────

    /**
     * Fallback image prompt for text presets requesting an image.
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
        $imgRef       = $this->imageReferenceBlock($attrs, 'image');

        return $this->joinLines([
            '## CONTEXT',
            "Product: \"{$product}\"" . $this->priceHint($price),
            "Campaign goal: {$goal}. Audience: {$audience}.",
            $usp !== '' ? "Core USP: {$usp}" : '',
            $offers !== '' ? "Promotions: {$offers}" : '',
            $expiration !== '' ? "Deadline: {$expiration}" : '',
            $imgRef,
            '',
            '## VISUAL DIRECTION',
            "Style: {$visualStyle}. Tone: {$tone}.",
            '',
            'Composition:',
            '- ONE clear hero product — 40-60% of frame.',
            '- Clean, premium layout with strong visual hierarchy.',
            '- Studio-quality lighting with controlled, natural shadows.',
            '- Background supports product context, never competes.',
            '- Reserve 20% top/bottom for potential text overlays.',
            '',
            'Text handling:',
            $headline !== '' ? "- Headline: \"{$headline}\" (clean sans-serif)" : '- No embedded text. Clean product photography only.',
            '',
            "## FORMAT: {$aspectRatio}, optimized for social media ads.",
        ]);
    }

    /**
     * Fallback video prompt for text presets requesting a video.
     */
    private function buildFallbackVideoPrompt(array $attrs): string
    {
        return $this->buildStructuredVideoPrompt($attrs, 'High-energy commercial realism');
    }
}
