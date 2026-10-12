<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/** Nonce-based CSP and the usual hardening headers (SEC-003). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        // Documents set their own, stricter policy.
        if (! $response->headers->has('Content-Security-Policy')) {
            $dev = app()->environment('local') && file_exists(public_path('hot'));
            $viteOrigin = $dev ? ' '.trim((string) file_get_contents(public_path('hot'))) : '';
            $viteWs = $dev ? ' ws://localhost:*' : '';

            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'{$viteOrigin}",
                "style-src 'self' 'unsafe-inline'{$viteOrigin}",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'{$viteOrigin}{$viteWs}",
                "frame-src 'self'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]));
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
