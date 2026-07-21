<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DriverAuthController extends Controller
{
    /**
     * POST /api/driver/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // ค้นหาทั้งจากคอลัมน์ username และ email
        $driver = Driver::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        if (!$driver || !$driver->password) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบ username นี้',
            ], 401);
        }

        if (!Hash::check($request->password, $driver->password)) {
            return response()->json([
                'success' => false,
                'message' => 'รหัสผ่านไม่ถูกต้อง',
            ], 401);
        }

        $driver->tokens()->delete();
        $token = $driver->createToken('driver-token')->plainTextToken;

        return response()->json([
            'success'    => true,
            'token'      => $token,
            'driver_id'  => $driver->driver_id,
            'avatar_url' => $driver->avatar_url,
            'status'     => $driver->status,
        ]);
    }

    /**
     * POST /api/driver/register
     */
    public function register(Request $request)
    {
        $request->validate([
            'username' => 'required|string|unique:drivers,username',
            'email'    => 'required|email|unique:drivers,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // สร้าง driver_id เป็น string random เนื่องจาก primary key เป็น char แล้ว
        $driverId = (string) \Illuminate\Support\Str::random(11);

        $driver = Driver::create([
            'driver_id'     => $driverId,  // เพิ่มเพราะ primary key เป็น char แล้ว
            'name'          => $request->username, // ตาราง drivers บังคับต้องมี name
            'username'      => $request->username,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'status'        => 1, // ต้องตรงกับ enum จริงในตาราง
            'auth_provider' => 'local',
        ]);

        $token = $driver->createToken('driver-token')->plainTextToken;

        return response()->json([
            'success'    => true,
            'token'      => $token,
            'driver_id'  => $driver->driver_id,
            'avatar_url' => $driver->avatar_url,
            'status'     => $driver->status,
        ], 201);
    }

    /**
     * POST /api/driver/google-login
     *
     * ✅ Endpoint เดียวที่ใช้สำหรับ Google Sign-In ทั้งหมด (mobile + web)
     * ตรวจสอบ idToken ด้วยการยิงไปหา Google โดยตรงผ่าน HTTP (ไม่ต้องพึ่ง
     * library google/apiclient) จึงไม่มีปัญหาเรื่อง Class not found
     */
    public function googleLogin(Request $request)
    {
        $request->validate(['id_token' => 'required|string']);

        $token   = $request->id_token;
        $payload = null;

        // ─── ขั้นที่ 1: ลอง tokeninfo (idToken แบบ JWT) ──────────────────────────
        if (substr_count($token, '.') === 2) {
            $tokenInfoResponse = Http::withoutVerifying()  // ✅ แก้ SSL สำหรับ local dev
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $token,
                ]);

            if ($tokenInfoResponse->ok()) {
                $data = $tokenInfoResponse->json();

                // ✅ Web Client ID เท่านั้น — ต้องตรงกับ serverClientId ฝั่ง Flutter เสมอ
                $validAudiences = [
                    '813400070963-t55qlrbag595qe51rmrq95m5k2sbn1om.apps.googleusercontent.com',
                ];

                if (isset($data['aud']) && in_array($data['aud'], $validAudiences, true)) {
                    $payload = $data;
                } else {
                    Log::warning('Google tokeninfo: aud ไม่ตรง', ['aud' => $data['aud'] ?? null]);
                }
            }
        }

        // ─── ขั้นที่ 2: fallback → userinfo (accessToken จาก web) ────────────────
        if ($payload === null) {
            $userInfoResponse = Http::withoutVerifying()  // ✅ แก้ SSL สำหรับ local dev
                ->withToken($token)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            if (!$userInfoResponse->ok()) {
                Log::error('Google userinfo failed', [
                    'status' => $userInfoResponse->status(),
                    'body'   => $userInfoResponse->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Google token ไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง',
                ], 401);
            }

            $payload = $userInfoResponse->json();
        }

        // ─── ขั้นที่ 3: ดึง field ที่ต้องการ ─────────────────────────────────────
        $googleId = $payload['sub'] ?? null;
        $email    = $payload['email'] ?? null;
        $picture  = $payload['picture'] ?? null;
        $name     = $payload['name'] ?? explode('@', $email ?? '')[0] ?? 'ผู้ใช้ Google';

        if (!$googleId || !$email) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถอ่านข้อมูลบัญชี Google ได้',
            ], 422);
        }

        // ─── ขั้นที่ 4: หา/สร้าง driver ─────────────────────────────────────────

        // 4a. หาจาก google_id ก่อน
        $driver = Driver::where('google_id', $googleId)->first();

        // 4b. หาจาก email → ผูก google_id
        if (!$driver) {
            $driver = Driver::where('email', $email)->first();
            if ($driver) {
                $driver->update([
                    'google_id'     => $googleId,
                    'auth_provider' => $driver->auth_provider === 'local' ? 'local' : 'google',
                    'avatar_url'    => $driver->avatar_url ?: $picture,
                    'name'          => $driver->name ?: $name,
                ]);
            }
        }

        // 4c. สร้างใหม่
        if (!$driver) {
            // ดึงชื่อหน้า @ ของอีเมลมาเป็นตัวตั้งต้น เช่น somchai@gmail.com -> somchai
            $usernamePrefix = explode('@', $email)[0];
            $username = $usernamePrefix . '_' . rand(1000, 9999);

            // วนลูปเช็คจนกว่าจะได้ username ที่ไม่ซ้ำกับใครในระบบเลย
            while (Driver::where('username', $username)->exists()) {
                $username = $usernamePrefix . '_' . rand(1000, 9999);
            }

            // สร้าง driver_id เป็น string random เนื่องจาก primary key เป็น char แล้ว
            $driverId = (string) \Illuminate\Support\Str::random(11);

            $driver = Driver::create([
                'driver_id'     => $driverId,  // เพิ่มเพราะ primary key เป็น char แล้ว
                'name'          => $name,
                'username'      => $username,    // ✨ เพิ่มบรรทัดนี้ เพื่อป้องกัน Database Error ค้างคา
                'email'         => $email,
                'google_id'     => $googleId,
                'avatar_url'    => $picture,
                'status'        => 1,
                'auth_provider' => 'google',
            ]);
        }

        // ─── ขั้นที่ 5: ออก Sanctum token ───────────────────────────────────────
        $driver->tokens()->delete();
        $sanctumToken = $driver->createToken('driver-token')->plainTextToken;

        return response()->json([
            'success'    => true,
            'token'      => $sanctumToken,
            'driver_id'  => $driver->driver_id,
            'avatar_url' => $driver->avatar_url,
            'status'     => $driver->status,
        ]);
    }

    /**
     * POST /api/driver/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'ออกจากระบบแล้ว',
        ]);
    }

    /**
     * POST /api/driver/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $driver = Driver::where('email', $request->email)->first();

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบ email นี้ในระบบ',
            ], 404);
        }

        if (!$driver->password && $driver->auth_provider === 'google') {
            return response()->json([
                'success' => false,
                'message' => 'บัญชีนี้เข้าสู่ระบบด้วย Google กรุณาเข้าสู่ระบบผ่านปุ่ม Google แทน',
            ], 422);
        }

        $otp = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($otp), 'created_at' => now()]
        );

        Mail::raw(
            "รหัสยืนยันสำหรับตั้งรหัสผ่านใหม่ของคุณคือ: {$otp}\nรหัสนี้จะหมดอายุภายใน 15 นาที หากคุณไม่ได้ทำรายการนี้ กรุณาเพิกเฉยต่ออีเมลฉบับนี้",
            function ($message) use ($driver) {
                $message->to($driver->email)
                    ->subject('Smart Drive Guard - รหัสยืนยันรีเซ็ตรหัสผ่าน');
            }
        );

        return response()->json([
            'success' => true,
            'message' => 'ส่งรหัสยืนยันไปที่ email แล้ว',
        ]);
    }

    /**
     * POST /api/driver/reset-password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->otp, $record->token)) {
            return response()->json([
                'success' => false,
                'message' => 'รหัสยืนยันไม่ถูกต้อง',
            ], 400);
        }

        if (now()->diffInMinutes($record->created_at) > 15) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'รหัสยืนยันหมดอายุแล้ว กรุณาขอรหัสใหม่',
            ], 400);
        }

        Driver::where('email', $request->email)->update([
            'password' => Hash::make($request->password),
        ]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'เปลี่ยนรหัสผ่านสำเร็จ',
        ]);
    }
}
