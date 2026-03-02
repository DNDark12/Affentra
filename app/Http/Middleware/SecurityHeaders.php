<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = (array) config('security.headers', []);
        $response->headers->set('X-Frame-Options', (string) ($headers['x_frame_options'] ?? 'DENY'));
        $response->headers->set('X-Content-Type-Options', (string) ($headers['x_content_type_options'] ?? 'nosniff'));
        $response->headers->set('Referrer-Policy', (string) ($headers['referrer_policy'] ?? 'strict-origin-when-cross-origin'));
        $response->headers->set('Permissions-Policy', (string) ($headers['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=()'));
        $response->headers->set(
            'X-Permitted-Cross-Domain-Policies',
            (string) ($headers['x_permitted_cross_domain_policies'] ?? 'none')
        );

        $hsts = (array) ($headers['hsts'] ?? []);
        if (($hsts['enabled'] ?? false) && $request->isSecure()) {
            $maxAge = max(0, (int) ($hsts['max_age'] ?? 31536000));
            $directives = ["max-age={$maxAge}"];

            if (($hsts['include_subdomains'] ?? true) === true) {
                $directives[] = 'includeSubDomains';
            }

            if (($hsts['preload'] ?? false) === true) {
                $directives[] = 'preload';
            }

            $response->headers->set('Strict-Transport-Security', implode('; ', $directives));
        }

        $csp = (array) config('security.csp', []);
        if (($csp['enabled'] ?? true) === true) {
            $policy = $this->buildCspPolicy($csp);
            $headerName = ($csp['report_only'] ?? true) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($headerName, $policy);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $csp
     */
    private function buildCspPolicy(array $csp): string
    {
        $segments = [];
        $directives = (array) ($csp['directives'] ?? []);

        foreach ($directives as $directive => $values) {
            $valueList = is_array($values) ? array_filter(array_map('strval', $values)) : [(string) $values];
            if ($valueList === []) {
                continue;
            }

            $segments[] = trim((string) $directive) . ' ' . implode(' ', $valueList);
        }

        $reportUri = trim((string) ($csp['report_uri'] ?? ''));
        if ($reportUri !== '') {
            $segments[] = 'report-uri ' . $reportUri;
        }

        return implode('; ', $segments);
    }
}
