<?php

declare(strict_types=1);

namespace App\Services\Alert;

use Illuminate\Support\Facades\Http;

class TelegramNotifierService
{
    /**
     * @return array{ok:bool,error:?string,error_code:?string,chat_id_used:?string}
     */
    public function sendMessage(string $botToken, string $chatId, string $text): array
    {
        $candidates = $this->chatIdCandidates($chatId);
        $lastError = [
            'ok' => false,
            'error' => 'Unknown error',
            'error_code' => 'unknown',
            'chat_id_used' => null,
        ];

        foreach ($candidates as $candidate) {
            $result = $this->sendMessageOnce($botToken, $candidate, $text);
            if ($result['ok']) {
                return $result;
            }

            $lastError = $result;
            // For token/network/permission problems, retrying with another id format won't help.
            if (($result['error_code'] ?? '') !== 'chat_not_found') {
                return $result;
            }
        }

        return $lastError;
    }

    /**
     * @return array{ok:bool,chat_id:?string,error:?string,error_code:?string}
     */
    public function resolveLatestChatId(string $botToken): array
    {
        try {
            $response = Http::timeout(10)->get(
                "https://api.telegram.org/bot{$botToken}/getUpdates",
                [
                    'limit' => 100,
                ],
            );

            if (! $response->ok()) {
                $payload = $response->json();
                $description = (string) ($payload['description'] ?? ('Telegram HTTP ' . $response->status()));
                $normalized = mb_strtolower($description);
                $errorCode = 'http_error';
                if ($response->status() === 401 || str_contains($normalized, 'unauthorized')) {
                    $errorCode = 'token_invalid';
                } elseif (
                    str_contains($normalized, 'chat not found')
                    || str_contains($normalized, 'chat_id is empty')
                    || str_contains($normalized, 'bot is not a member')
                ) {
                    $errorCode = 'chat_not_found';
                }

                return [
                    'ok' => false,
                    'chat_id' => null,
                    'error' => $description,
                    'error_code' => $errorCode,
                ];
            }

            $payload = $response->json();
            if (! (bool) ($payload['ok'] ?? false)) {
                $description = (string) ($payload['description'] ?? 'Telegram API error');
                return [
                    'ok' => false,
                    'chat_id' => null,
                    'error' => $description,
                    'error_code' => str_contains(mb_strtolower($description), 'unauthorized')
                        ? 'token_invalid'
                        : 'api_error',
                ];
            }

            $updates = (array) ($payload['result'] ?? []);
            $chatId = null;

            foreach (array_reverse($updates) as $update) {
                if (! is_array($update)) {
                    continue;
                }

                $candidate = $update['message']['chat']['id']
                    ?? $update['channel_post']['chat']['id']
                    ?? $update['edited_message']['chat']['id']
                    ?? null;

                if ($candidate !== null && $candidate !== '') {
                    $chatId = (string) $candidate;
                    break;
                }
            }

            if ($chatId === null) {
                return [
                    'ok' => false,
                    'chat_id' => null,
                    'error' => 'No chat found in getUpdates',
                    'error_code' => 'chat_not_found',
                ];
            }

            return [
                'ok' => true,
                'chat_id' => $chatId,
                'error' => null,
                'error_code' => null,
            ];
        } catch (\Throwable $error) {
            $errorCode = str_contains(mb_strtolower($error->getMessage()), 'timed out')
                ? 'network_timeout'
                : 'network_error';

            return [
                'ok' => false,
                'chat_id' => null,
                'error' => $error->getMessage(),
                'error_code' => $errorCode,
            ];
        }
    }

    /**
     * @return array{ok:bool,error:?string,error_code:?string,chat_id_used:?string}
     */
    private function sendMessageOnce(string $botToken, string $chatId, string $text): array
    {
        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $text,
                ],
            );

            if (! $response->ok()) {
                $payload = $response->json();
                $description = (string) ($payload['description'] ?? ('Telegram HTTP ' . $response->status()));
                $normalized = mb_strtolower($description);
                $errorCode = 'http_error';

                if ($response->status() === 401 || str_contains($normalized, 'unauthorized')) {
                    $errorCode = 'token_invalid';
                } elseif (
                    str_contains($normalized, 'chat not found')
                    || str_contains($normalized, 'chat_id is empty')
                    || str_contains($normalized, 'not enough rights')
                    || str_contains($normalized, 'bot is not a member')
                ) {
                    $errorCode = 'chat_not_found';
                } elseif (
                    str_contains($normalized, 'bot was blocked')
                    || str_contains($normalized, 'bot was kicked')
                    || str_contains($normalized, 'forbidden')
                ) {
                    $errorCode = 'bot_blocked';
                }

                return [
                    'ok' => false,
                    'error' => $description,
                    'error_code' => $errorCode,
                    'chat_id_used' => $chatId,
                ];
            }

            $payload = $response->json();
            $ok = (bool) ($payload['ok'] ?? false);

            if (! $ok) {
                $description = (string) ($payload['description'] ?? 'Telegram API error');
                $normalized = mb_strtolower($description);
                $errorCode = 'api_error';

                if (str_contains($normalized, 'chat not found')) {
                    $errorCode = 'chat_not_found';
                } elseif (str_contains($normalized, 'bot was blocked')) {
                    $errorCode = 'bot_blocked';
                } elseif (str_contains($normalized, 'unauthorized')) {
                    $errorCode = 'token_invalid';
                }

                return [
                    'ok' => false,
                    'error' => $description,
                    'error_code' => $errorCode,
                    'chat_id_used' => $chatId,
                ];
            }

            return [
                'ok' => true,
                'error' => null,
                'error_code' => null,
                'chat_id_used' => $chatId,
            ];
        } catch (\Throwable $error) {
            $errorCode = 'network_error';
            if (str_contains(mb_strtolower($error->getMessage()), 'timed out')) {
                $errorCode = 'network_timeout';
            }

            return [
                'ok' => false,
                'error' => $error->getMessage(),
                'error_code' => $errorCode,
                'chat_id_used' => $chatId,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function chatIdCandidates(string $chatId): array
    {
        $value = trim($chatId);
        if ($value === '') {
            return [];
        }

        $candidates = [$value];
        if (! preg_match('/^-?\\d+$/', $value)) {
            return $candidates;
        }

        if (str_starts_with($value, '-100')) {
            $without = '-' . ltrim(substr($value, 4), '-');
            if ($without !== '-' && $without !== $value) {
                $candidates[] = $without;
            }
            return array_values(array_unique($candidates));
        }

        if (str_starts_with($value, '-')) {
            $withPrefix = '-100' . ltrim($value, '-');
            if ($withPrefix !== $value) {
                $candidates[] = $withPrefix;
            }
        }

        return array_values(array_unique($candidates));
    }
}
