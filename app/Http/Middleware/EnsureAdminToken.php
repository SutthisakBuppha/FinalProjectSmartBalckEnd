<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() instanceof Admin) {
            return response()->json([
                'success' => false,
                'message' => 'Admin token required',
            ], 403);
        }

        return $next($request);
    }
}
