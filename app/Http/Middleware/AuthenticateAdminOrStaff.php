<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdminOrStaff
{
    private const IMS_ROLES = ['admin', 'manager', 'product_manager', 'sales_staff', 'warehouse'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->hasAnyRole(self::IMS_ROLES)) {
            return response()->json(['message' => 'Forbidden. Staff access required.'], 403);
        }

        return $next($request);
    }
}
