<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a specific Spatie permission.
 *
 * Usage in routes: ->middleware('permission:products.create')
 *        multiple: ->middleware('permission:products.create,products.edit')  (any one suffices)
 *
 * The user must already be authenticated via admin_or_sales middleware before this runs.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden. You do not have the required permission.',
            'required' => $permissions,
        ], 403);
    }
}
