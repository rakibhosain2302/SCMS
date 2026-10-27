<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nit\Master\Support\Branches;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff without a branch are refused on branch-kept pages. nit-master would let them see every
 * branch; a forgotten assignment must not open all members (scms-finance applies the same rule).
 */
class EnsureUserHasBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && Branches::enabled() && ! $user->isSuperAdmin() && Branches::assignedIds($user) === []) {
            abort(403, __('You are not assigned to a branch yet. Ask an administrator to add you to one.'));
        }

        return $next($request);
    }
}
