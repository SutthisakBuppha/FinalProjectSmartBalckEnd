<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IotApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-KEY');

        if (!$key || $key !== config('services.iot.api_key')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized device'], 401);
        }

        return $next($request);
    }
}
