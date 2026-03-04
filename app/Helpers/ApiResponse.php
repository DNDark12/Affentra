<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Standard success envelope.
     *
     * @param  mixed  $data
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'ok'      => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
            'code'    => null,
        ], $status);
    }

    /**
     * Standard error envelope.
     *
     * @param  array<string, list<string>>|null  $errors
     */
    public static function error(
        string $message,
        ?array $errors = null,
        int $status = 422,
        ?string $code = null,
    ): JsonResponse {
        return response()->json([
            'ok'      => false,
            'data'    => null,
            'message' => $message,
            'errors'  => $errors,
            'code'    => $code,
        ], $status);
    }

    /**
     * 201 Created.
     *
     * @param  mixed  $data
     */
    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * 404 Not Found.
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return self::error($message, null, 404);
    }

    /**
     * 403 Forbidden.
     */
    public static function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return self::error($message, null, 403);
    }

    /**
     * 500 Server Error.
     */
    public static function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return self::error($message, null, 500);
    }
}
