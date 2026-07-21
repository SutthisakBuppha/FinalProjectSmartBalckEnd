<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $request->username)->first();

        if (!$admin) {
            return response()->json(['success' => false, 'message' => 'ไม่พบ username นี้'], 401);
        }

        if (!Hash::check($request->password, $admin->password)) {
            return response()->json(['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json(['success' => true, 'token' => $token, 'admin' => $admin]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'ออกจากระบบแล้ว']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $admin = Admin::where('email', $request->email)->first();

        if (!$admin) {
            return response()->json(['success' => false, 'message' => 'ไม่พบ email นี้ในระบบ'], 404);
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . $request->email;

        Mail::to($admin->email)->send(new ResetPasswordMail($resetUrl, $admin->full_name));

        return response()->json(['success' => true, 'message' => 'ส่งลิงก์รีเซ็ตรหัสผ่านไปที่ email แล้ว']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['success' => false, 'message' => 'token ไม่ถูกต้อง'], 400);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['success' => false, 'message' => 'token หมดอายุแล้ว'], 400);
        }

        Admin::where('email', $request->email)->update([
            'password' => Hash::make($request->password)
        ]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['success' => true, 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ']);
    }
}
