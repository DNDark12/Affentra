<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        app()->instance('request_id', $requestId);

        Log::withContext([
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = trim((string) $request->headers->get('X-Request-ID', ''));

        if ($incoming !== '' && strlen($incoming) <= 100 && preg_match('/^[A-Za-z0-9._\-]+$/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
