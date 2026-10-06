<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $previousState
     * @param  array<string, mixed>|null  $newState
     */
    public function log(
        User|int|null $actor,
        string $action,
        string|Model $target,
        int|string|null $targetId = null,
        ?array $previousState = null,
        ?array $newState = null,
        ?string $reason = null,
        ?Request $request = null,
    ): void {
        [$targetType, $resolvedTargetId] = $this->resolveTarget($target, $targetId);
        $resolvedRequest = $request ?? request();

        AuditLog::query()->create([
            'actor_id' => $actor instanceof User ? $actor->id : (is_int($actor) ? $actor : null),
            'target_type' => $targetType,
            'target_id' => $resolvedTargetId,
            'action' => $action,
            'previous_state' => $this->sanitizeState($previousState),
            'new_state' => $this->sanitizeState($newState),
            'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            'ip_address' => $resolvedRequest?->ip(),
            'user_agent' => $resolvedRequest?->userAgent() ? mb_substr((string) $resolvedRequest->userAgent(), 0, 512) : null,
            'request_id' => $this->resolveRequestId($resolvedRequest),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    private function sanitizeState(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'app_secret',
            'access_token',
            'refresh_token',
            'cookie_header',
            'curl_command',
            'token',
            'secret',
            'shop_cipher',
            'tmp_auth_key',
        ];

        $sanitize = function (mixed $value) use (&$sanitize, $sensitiveKeys): mixed {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item) {
                    $normalizedKey = mb_strtolower((string) $key);
                    $isSensitive = in_array($normalizedKey, $sensitiveKeys, true)
                        || str_contains($normalizedKey, 'token')
                        || str_contains($normalizedKey, 'secret');

                    if ($isSensitive) {
                        $result[$key] = '[REDACTED]';
                        continue;
                    }

                    $result[$key] = $sanitize($item);
                }

                return $result;
            }

            return $value;
        };

        /** @var array<string, mixed> */
        return $sanitize($state);
    }

    /**
     * @return array{0:string,1:int|string|null}
     */
    private function resolveTarget(string|Model $target, int|string|null $targetId): array
    {
        if ($target instanceof Model) {
            return [$target::class, $target->getKey()];
        }

        return [$target, $targetId];
    }

    private function resolveRequestId(?Request $request): ?string
    {
        if ($request !== null) {
            $requestId = (string) $request->attributes->get('request_id', '');
            if ($requestId !== '') {
                return $requestId;
            }
        }

        if (app()->bound('request_id')) {
            $requestId = (string) app('request_id');
            if ($requestId !== '') {
                return $requestId;
            }
        }

        return null;
    }
}
