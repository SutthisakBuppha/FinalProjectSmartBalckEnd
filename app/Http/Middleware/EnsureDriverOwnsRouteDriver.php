<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverOwnsRouteDriver
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof Driver) {
            return $next($request);
        }

        $routeDriver = $request->route('driver');
        $routeDriverId = $routeDriver instanceof Driver
            ? $routeDriver->getKey()
            : $routeDriver;

        if ((int) $user->driver_id !== (int) $routeDriverId) {
            return response()->json([
                'success' => false,
                'message' => 'You can only access your own driver data',
            ], 403);
        }

        return $next($request);
    }
}
