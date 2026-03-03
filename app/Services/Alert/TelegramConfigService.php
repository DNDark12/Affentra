<?php

declare(strict_types=1);

namespace App\Services\Alert;

use App\Models\User;
use App\Models\UserTelegramConfig;
use App\Services\Audit\AuditLogger;

class TelegramConfigService
{
    public function __construct(
        private readonly TelegramNotifierService $telegramNotifierService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{
     *   enabled:bool,
     *   has_chat_id:bool,
     *   has_group_id:bool,
     *   has_token:bool,
     *   default_chat_id:?string,
     *   group_id:?string,
     *   masked_bot_token:?string,
     *   fallback_to_in_app:bool,
     *   last_test_status:?string,
     *   last_test_error:?string,
     *   last_test_at:?string,
     *   verified_at:?string
     * }
     */
    public function status(User $user): array
    {
        $config = $this->findByUserId((int) $user->id);

        if ($config === null) {
            return [
                'enabled' => false,
                'has_chat_id' => false,
                'has_group_id' => false,
                'has_token' => false,
                'default_chat_id' => null,
                'group_id' => null,
                'masked_bot_token' => null,
                'fallback_to_in_app' => true,
                'last_test_status' => null,
                'last_test_error' => null,
                'last_test_at' => null,
                'verified_at' => null,
            ];
        }

        return [
            'enabled' => (bool) $config->is_enabled,
            'has_chat_id' => $this->safeTrim($config->default_chat_id) !== '',
            'has_group_id' => $this->safeTrim($config->group_id) !== '',
            'has_token' => $this->safeTrim($config->bot_token) !== '',
            'default_chat_id' => $config->default_chat_id,
            'group_id' => $config->group_id,
            'masked_bot_token' => $this->maskToken($config->bot_token),
            'fallback_to_in_app' => (bool) $config->fallback_to_in_app,
            'last_test_status' => $config->last_test_status,
            'last_test_error' => $config->last_test_error,
            'last_test_at' => $config->last_test_at?->toIso8601String(),
            'verified_at' => $config->verified_at?->toIso8601String(),
        ];
    }

    public function findByUserId(int $userId): ?UserTelegramConfig
    {
        return UserTelegramConfig::query()
            ->where('user_id', $userId)
            ->first();
    }

    public function findEnabledByUserId(int $userId): ?UserTelegramConfig
    {
        $config = $this->findByUserId($userId);
        if ($config === null) {
            return null;
        }

        if (! $config->is_enabled) {
            return null;
        }

        if ($this->safeTrim($config->bot_token) === '') {
            return null;
        }

        // If group id exists, Telegram can always target that group directly.
        if ($this->safeTrim($config->group_id) !== '') {
            return $config;
        }

        if ($this->safeTrim($config->default_chat_id) === '') {
            $resolved = $this->telegramNotifierService->resolveLatestChatId((string) $config->bot_token);
            if (! $resolved['ok'] || $this->safeTrim((string) ($resolved['chat_id'] ?? '')) === '') {
                return null;
            }

            $config->default_chat_id = (string) $resolved['chat_id'];
            $config->save();
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, array $validated): UserTelegramConfig
    {
        $config = $this->findByUserId((int) $user->id);
        $previous = $config ? $this->auditState($config) : null;

        if ($config === null) {
            $config = new UserTelegramConfig();
            $config->user_id = (int) $user->id;
        }

        $existingToken = $this->safeTrim($config->bot_token);
        $existingGroupId = $this->safeTrim($config->group_id);
        $providedToken = trim((string) ($validated['bot_token'] ?? ''));
        $finalToken = $providedToken !== '' ? $providedToken : $existingToken;
        if ($finalToken === '') {
            throw new \InvalidArgumentException('Bot token là bắt buộc khi cấu hình Telegram lần đầu.');
        }

        $hasGroupIdInPayload = array_key_exists('group_id', $validated);
        $newGroupId = $hasGroupIdInPayload
            ? $this->normalizeTelegramTargetId(trim((string) ($validated['group_id'] ?? '')))
            : $existingGroupId;

        $tokenChanged = $existingToken !== $finalToken;
        $groupChanged = $existingGroupId !== $newGroupId;

        $config->bot_token = $finalToken;
        $config->group_id = $newGroupId !== '' ? $newGroupId : null;
        $config->fallback_to_in_app = (bool) ($validated['fallback_to_in_app'] ?? true);
        $config->is_enabled = (bool) ($validated['is_enabled'] ?? true);

        if ($tokenChanged || $groupChanged) {
            $config->verified_at = null;
            $config->last_test_status = null;
            $config->last_test_error = null;
            if ($tokenChanged) {
                // Reset auto-discovered direct chat id when token changes.
                $config->default_chat_id = null;
            }
        }

        $config->save();

        $this->auditLogger->log(
            actor: $user,
            action: 'alert.telegram.updated',
            target: $config,
            previousState: $previous,
            newState: $this->auditState($config),
        );

        return $config;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{ok:bool,message:string,error_code:?string}
     */
    public function test(User $user, array $validated): array
    {
        $config = $this->findByUserId((int) $user->id);
        $overrideToken = trim((string) ($validated['bot_token'] ?? ''));
        $overrideGroupId = $this->normalizeTelegramTargetId(trim((string) ($validated['group_id'] ?? '')));
        $storedToken = $config ? $this->safeTrim($config->bot_token) : '';
        $storedGroupId = $config ? $this->normalizeTelegramTargetId($this->safeTrim($config->group_id)) : '';
        $storedChatId = $config ? $this->safeTrim($config->default_chat_id) : '';

        $botToken = $overrideToken !== '' ? $overrideToken : $storedToken;
        $chatId = $overrideGroupId !== ''
            ? $overrideGroupId
            : ($storedGroupId !== '' ? $storedGroupId : $storedChatId);

        if ($botToken === '') {
            return [
                'ok' => false,
                'message' => 'Thiếu bot token. Nhập token ở Test nhanh hoặc lưu cấu hình trước.',
                'error_code' => 'not_configured',
            ];
        }

        $usesStoredConfig = $config !== null
            && ($overrideToken === '' || $overrideToken === $storedToken)
            && ($overrideGroupId === '' || $overrideGroupId === $storedGroupId);
        $message = trim((string) ($validated['message'] ?? 'Affentra: Telegram connection test.'));
        if ($message === '') {
            $message = 'Affentra: Telegram connection test.';
        }

        if ($chatId === '') {
            $chatResolve = $this->telegramNotifierService->resolveLatestChatId($botToken);
            if (! $chatResolve['ok'] || $chatResolve['chat_id'] === null || $chatResolve['chat_id'] === '') {
                $messageFromResolve = $this->messageForTestResult(
                    false,
                    (string) ($chatResolve['error_code'] ?? 'chat_not_found'),
                    (string) ($chatResolve['error'] ?? '')
                );

                return [
                    'ok' => false,
                    'message' => $messageFromResolve . ' Hãy nhắn cho bot hoặc thêm bot vào group rồi thử lại.',
                    'error_code' => (string) ($chatResolve['error_code'] ?? 'chat_not_found'),
                ];
            }

            $chatId = (string) $chatResolve['chat_id'];

            if ($usesStoredConfig && $config !== null && $storedGroupId === '') {
                $config->default_chat_id = $chatId;
            }
        }

        $result = $this->telegramNotifierService->sendMessage($botToken, $chatId, $message);

        if ($usesStoredConfig && $config !== null) {
            $config->last_test_at = now();
            $config->last_test_status = $result['ok'] ? 'success' : 'failed';
            $config->last_test_error = $result['ok'] ? null : mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 2000);
            if ($result['ok']) {
                $config->verified_at = now();
            }
            $config->save();
        }

        $this->auditLogger->log(
            actor: $user,
            action: 'alert.telegram.tested',
            target: $config ?? UserTelegramConfig::class,
            targetId: $config?->id,
            newState: [
                'ok' => $result['ok'],
                'chat_id' => $this->maskChatId($chatId),
                'last_test_status' => $usesStoredConfig && $config !== null
                    ? $config->last_test_status
                    : ($result['ok'] ? 'success' : 'failed'),
                'error_code' => $result['error_code'],
                'used_override_token' => $overrideToken !== '',
                'used_override_group_id' => $overrideGroupId !== '',
            ],
        );

        $message = $this->messageForTestResult($result['ok'], (string) ($result['error_code'] ?? ''), (string) ($result['error'] ?? ''));

        return [
            'ok' => $result['ok'],
            'message' => $message,
            'error_code' => $result['error_code'],
        ];
    }

    public function delete(User $user): void
    {
        $config = $this->findByUserId((int) $user->id);
        if ($config === null) {
            return;
        }

        $previous = $config->toArray();
        $config->delete();

        $this->auditLogger->log(
            actor: $user,
            action: 'alert.telegram.deleted',
            target: UserTelegramConfig::class,
            targetId: $previous['id'] ?? null,
            previousState: $this->auditStateFromArray($previous),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditState(UserTelegramConfig $config): array
    {
        return [
            'id' => (int) $config->id,
            'user_id' => (int) $config->user_id,
            'has_token' => $this->safeTrim($config->bot_token) !== '',
            'masked_bot_token' => $this->maskToken($config->bot_token),
            'default_chat_id' => $this->maskChatId($config->default_chat_id),
            'group_id' => $this->maskChatId($config->group_id),
            'is_enabled' => (bool) $config->is_enabled,
            'fallback_to_in_app' => (bool) $config->fallback_to_in_app,
            'last_test_status' => $config->last_test_status,
            'verified_at' => $config->verified_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function auditStateFromArray(array $payload): array
    {
        return [
            'id' => (int) ($payload['id'] ?? 0),
            'user_id' => (int) ($payload['user_id'] ?? 0),
            'has_token' => $this->safeTrim((string) ($payload['bot_token'] ?? '')) !== '',
            'masked_bot_token' => $this->maskToken((string) ($payload['bot_token'] ?? '')),
            'default_chat_id' => $this->maskChatId((string) ($payload['default_chat_id'] ?? '')),
            'group_id' => $this->maskChatId((string) ($payload['group_id'] ?? '')),
            'is_enabled' => (bool) ($payload['is_enabled'] ?? false),
            'fallback_to_in_app' => (bool) ($payload['fallback_to_in_app'] ?? true),
            'last_test_status' => $payload['last_test_status'] ?? null,
            'verified_at' => $payload['verified_at'] ?? null,
        ];
    }

    private function maskChatId(?string $chatId): ?string
    {
        $value = $this->safeTrim($chatId);
        if ($value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . mb_substr($value, -4);
    }

    private function messageForTestResult(bool $ok, string $errorCode, string $errorMessage): string
    {
        if ($ok) {
            return 'Telegram test thành công.';
        }

        return match ($errorCode) {
            'token_invalid' => 'Telegram test thất bại: bot token không hợp lệ.',
            'chat_not_found' => 'Telegram test thất bại: chat id không tồn tại hoặc bot chưa được thêm vào chat.',
            'bot_blocked' => 'Telegram test thất bại: bot đã bị chặn bởi tài khoản/chat đích.',
            'network_timeout' => 'Telegram test thất bại: timeout kết nối Telegram. Vui lòng thử lại.',
            'network_error' => 'Telegram test thất bại: lỗi mạng khi gọi Telegram API.',
            default => 'Telegram test thất bại: ' . ($errorMessage !== '' ? $errorMessage : 'Unknown error'),
        };
    }

    private function normalizeTelegramTargetId(string $value): string
    {
        $input = trim($value);
        if ($input === '' || str_starts_with($input, '@')) {
            return $input;
        }

        return $input;
    }

    private function maskToken(?string $token): ?string
    {
        $value = $this->safeTrim($token);
        if ($value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 4) . str_repeat('*', $length - 8) . mb_substr($value, -4);
    }

    private function safeTrim(?string $value): string
    {
        return trim((string) $value);
    }
}
