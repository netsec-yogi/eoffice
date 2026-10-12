<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vendors and other outside parties reach only their portal and the few
 * shared routes it needs (Brief §14.3). Every controller still authorises
 * the record itself; this is the outer wall, so a new internal page can
 * never become visible to a vendor by omission.
 */
class ConfineExternalUsers
{
    private const ALLOWED = [
        'vendor.*', 'logout', 'documents.show', 'documents.store',
        'requests.create', 'requests.store', 'requests.edit', 'requests.update',
        'notifications.index', 'notifications.read', 'official-documents.index', 'official-documents.download',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isExternal()) {
            return $next($request);
        }
        if ($request->routeIs('dashboard')) {
            return redirect()->route('vendor.portal');
        }
        abort_unless($request->routeIs(...self::ALLOWED), 403, 'This page is not available to external accounts.');

        return $next($request);
    }
}
