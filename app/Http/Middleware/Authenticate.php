<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // ปล่อยเป็น null ไว้แบบนี้แหละครับ ถูกแล้ว
        return null;
    }

    /**
     * เขียนฟังก์ชันนี้เพิ่มเข้าไปเพื่อตัดวงจรการ Redirect ทั้งหมด
     */
    protected function unauthenticated($request, array $guards)
    {
        // สั่ง abort และพ่น JSON ออกไปทันที ไม่ว่าจะส่ง Header มาแบบไหนก็ตาม
        abort(response()->json([
            'status' => false,
            'message' => 'Unauthenticated. Please provide a valid token.',
        ], 401));
    }
}
