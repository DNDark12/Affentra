<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Registry for generating system prompts based on modality and context.
 * 
 * Separation of concerns:
 * - PromptTemplateRegistry handles the Subject/Asset Brief.
 * - SystemPromptRegistry handles the Persona/Rules/Safety/Safety/Constraints.
 */
class SystemPromptRegistry
{
    /**
     * Resolve the full system prompt for a given modality and context.
     */
    public function getSystemPrompt(string $modality, array $context = []): string
    {
        $blocks = [];

        // 1. Base Modality Instruction
        $blocks[] = $this->getBaseModalityBlock($modality);

        // 2. Platform/Contextual Hook
        $platform = $context['platform'] ?? 'generic';
        $blocks[] = $this->getPlatformBlock($platform, $modality);

        // 3. Language Constraint
        $language = $context['language'] ?? 'Vietnamese';
        $blocks[] = "Language: Always output in {$language} unless explicitly requested otherwise.";

        // 4. Policy/Safety Blocks
        $policyFlags = $context['policy_flags'] ?? [];
        $blocks[] = $this->getPolicyBlock($policyFlags);

        // 5. Formatting/Operational rules
        $blocks[] = $this->getFormattingBlock($modality);

        return implode("\n\n", array_filter($blocks));
    }

    private function getBaseModalityBlock(string $modality): string
    {
        return match ($modality) {
            'text'  => "Role: You are a professional affiliate marketing content specialist. Your goal is to write high-converting, engaging, and persuasive copy.",
            'image' => "Role: You are an expert AI image prompt engineer and visual designer. Your goal is to translate a product brief into a high-quality, realistic, and commercially viable visual asset.",
            'video' => "Role: You are an expert short-form video director and motion designer. Your goal is to define a high-energy, visually stunning, and logically paced video sequence based on a product brief.",
            default => throw new InvalidArgumentException("Unsupported modality: [{$modality}]"),
        };
    }

    private function getPlatformBlock(string $platform, string $modality): string
    {
        if ($modality !== 'text') {
            return match ($platform) {
                'facebook' => "Platform: Optimized for Facebook Feed/Ads (4:5 or 1:1, strong visual hierarchy).",
                'tiktok'   => "Platform: Optimized for TikTok/Reels (9:16 vertical, energetic visuals).",
                'shopee'   => "Platform: Optimized for Shopee product pages (1:1, clean, high visibility).",
                default    => "Platform: Generic social media asset.",
            };
        }

        return match ($platform) {
            'facebook' => "Strategy: Focus on stopping the scroll with sharp hooks. Use natural social language, not 'corporate' speak.",
            'tiktok'   => "Strategy: High energy, catchy hooks, and relatable 'creator' tone. Use trending patterns.",
            'shopee'   => "Strategy: SEO-driven naming, clear benefits, and trust-building language.",
            default    => "Strategy: General persuasive marketing copy.",
        };
    }

    private function getPolicyBlock(array $flags): string
    {
        $rules = [
            "Global Principle: DO NOT fabricate facts, prices, reviews, or specifications that are not provided in the input.",
        ];

        if ($flags['safety_no_absolute'] ?? false) {
            $rules[] = "- Avoid absolute claims: Do not use words like 'nhất' (best), '100%', 'tuyệt đối' (absolute), or 'chắc chắn' (guaranteed). Use softer, supportive language.";
        }

        if ($flags['safety_no_medical'] ?? false) {
            $rules[] = "- No medical claims: Do not suggest health benefits, cures, or medical results that could violate advertising policies.";
        }

        if ($flags['safety_no_sensitive'] ?? false) {
            $rules[] = "- No sensitive content: Avoid topics that are controversial, shocking, discriminatory, or political.";
        }

        return "Constraints and Safety:\n" . implode("\n", $rules);
    }

    private function getFormattingBlock(string $modality): string
    {
        if ($modality === 'text') {
            return "Output Format: Provide the requested variants. Separate different versions using '---' on a new line. Do not include numbering, explanations, or meta-talk.";
        }

        return "Technical Standards: Focus on realism, consistency, and commercial appeal. Avoid watermarks, distorted anatomy, and messy backgrounds.";
    }
}
