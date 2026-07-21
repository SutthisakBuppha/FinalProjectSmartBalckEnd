<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DeviceSettingController;
use App\Http\Controllers\Api\DriverDeviceController;
use App\Http\Controllers\Api\TripLocationController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\DriverAuthController;
use App\Http\Controllers\Api\DriverDashboardController;
use App\Models\Alert;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// ── Admin Auth (throttle กันโดน brute force) ────────────────
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
});

// ── Driver Auth (ไม่ต้องการ token) ──────────────────────────
Route::post('driver/login',           [DriverAuthController::class, 'login']);
Route::post('driver/register',        [DriverAuthController::class, 'register']);
Route::post('driver/forgot-password', [DriverAuthController::class, 'forgotPassword']);
Route::post('driver/reset-password',  [DriverAuthController::class, 'resetPassword']);
Route::post('driver/google-login',    [DriverAuthController::class, 'googleLogin']);

// ── IoT devices (ESP32-CAM) — ใช้ API key ──────────────────
Route::middleware('iot.apikey')->group(function () {
    // 📌 ย้ายการยิง Alert จาก AI Guard มาเข้า endpoint นี้แทน (ทำผ่าน AlertController)
    Route::post('/alerts',                    [AlertController::class, 'store']);
    Route::post('/trips/{trip}/locations',    [TripLocationController::class, 'store']);
});

// ── Driver App routes (Sanctum token ของคนขับ) ───────────────
Route::middleware(['auth:sanctum', 'driver.owner', 'throttle:60,1'])
    ->prefix('drivers/{driver}')
    ->group(function () {
        Route::post('logout', [DriverAuthController::class, 'logout']);
        Route::get('dashboard', [DriverDashboardController::class, 'index']);
        Route::get('trips/{trip}/summary', [DriverDashboardController::class, 'tripSummary']);
        Route::get('alerts/summary', [DriverDashboardController::class, 'alertSummary']);
    });

// ── Flutter App routes (Sanctum token ของ Driver หรือ Admin) ───────
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    Route::prefix('app/drivers/{driver}')
        ->middleware('driver.owner')
        ->group(function () {
            Route::get('/',    [AppController::class, 'showDriver']);
            Route::put('/',    [AppController::class, 'updateDriver']);
            Route::patch('/',  [AppController::class, 'updateDriver']);

            // Devices
            Route::get('devices',                     [AppController::class, 'devices']);
            Route::post('devices',                    [AppController::class, 'storeDevice']);
            Route::get('devices/{device}',            [AppController::class, 'showDevice']);
            Route::put('devices/{device}',            [AppController::class, 'updateDevice']);
            Route::patch('devices/{device}',          [AppController::class, 'updateDevice']);
            Route::delete('devices/{device}',         [AppController::class, 'destroyDevice']);
            Route::get('devices/{device}/setting',    [AppController::class, 'deviceSetting']);
            Route::put('devices/{device}/setting',    [AppController::class, 'upsertDeviceSetting']);
            Route::patch('devices/{device}/setting',  [AppController::class, 'upsertDeviceSetting']);

            // Trips
            Route::get('trips',                                    [AppController::class, 'trips']);
            Route::post('trips',                                   [AppController::class, 'storeTrip']);
            Route::get('trips/{trip}',                             [AppController::class, 'showTrip']);
            Route::put('trips/{trip}',                             [AppController::class, 'updateTrip']);
            Route::patch('trips/{trip}',                           [AppController::class, 'updateTrip']);
            Route::delete('trips/{trip}',                          [AppController::class, 'destroyTrip']);
            Route::get('trips/{trip}/locations',                   [AppController::class, 'tripLocations']);
            Route::post('trips/{trip}/locations',                  [AppController::class, 'storeTripLocation']);
            Route::get('trips/{trip}/locations/{location}',        [AppController::class, 'showTripLocation']);
            Route::put('trips/{trip}/locations/{location}',        [AppController::class, 'updateTripLocation']);
            Route::patch('trips/{trip}/locations/{location}',      [AppController::class, 'updateTripLocation']);
            Route::delete('trips/{trip}/locations/{location}',     [AppController::class, 'destroyTripLocation']);

            // Alerts
            Route::get('alerts',            [AppController::class, 'alerts']);
            Route::post('alerts',           [AppController::class, 'storeAlert']);
            Route::get('alerts/{alert}',    [AppController::class, 'showAlert']);
            Route::put('alerts/{alert}',    [AppController::class, 'updateAlert']);
            Route::patch('alerts/{alert}',  [AppController::class, 'updateAlert']);
            Route::delete('alerts/{alert}', [AppController::class, 'destroyAlert']);

            // Notifications
            Route::patch('notifications/read-all',              [AppController::class, 'markAllNotificationsRead']);
            Route::get('notifications',                         [AppController::class, 'notifications']);
            Route::post('notifications',                        [AppController::class, 'storeNotification']);
            Route::get('notifications/{notification}',          [AppController::class, 'showNotification']);
            Route::put('notifications/{notification}',          [AppController::class, 'updateNotification']);
            Route::patch('notifications/{notification}',        [AppController::class, 'updateNotification']);
            Route::patch('notifications/{notification}/read',   [AppController::class, 'markNotificationRead']);
            Route::delete('notifications/{notification}',       [AppController::class, 'destroyNotification']);
        });
});

// ── Admin / Dashboard routes (Sanctum token ของ Admin) ───────
Route::middleware(['auth:sanctum', 'admin.token', 'throttle:60,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('drivers',         DriverController::class);
    Route::apiResource('devices',         DeviceController::class);
    Route::apiResource('trips',           TripController::class);
    Route::apiResource('device-settings', DeviceSettingController::class);

    Route::get('alerts',            [AlertController::class, 'index']);
    Route::get('alerts/{alert}',    [AlertController::class, 'show']);
    Route::put('alerts/{alert}',    [AlertController::class, 'update']);
    Route::patch('alerts/{alert}',  [AlertController::class, 'update']);
    Route::delete('alerts/{alert}', [AlertController::class, 'destroy']);

    Route::get('trips/{trip}/locations', [TripLocationController::class, 'index']);

    Route::patch('notifications/read-all',  [NotificationController::class, 'markAllAsRead']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('notifications',     NotificationController::class);

    Route::get('drivers/{driver}/devices',                     [DriverDeviceController::class, 'index']);
    Route::post('drivers/{driver}/devices/{device}/assign',    [DriverDeviceController::class, 'assign']);
    Route::patch('drivers/{driver}/devices/{device}/unassign', [DriverDeviceController::class, 'unassign']);
});

Route::post('/devices/heartbeat', [DeviceController::class, 'heartbeat']);

// ── Device media ──
Route::post('/device-media/upload',      [DeviceController::class, 'uploadMedia']);
Route::get('/device-media/{deviceId}',   [DeviceController::class, 'indexMedia']);
Route::delete('/device-media/{mediaId}', [DeviceController::class, 'destroyMedia']);

// 🔄 [GET] ดึงประวัติ Alert ล่าสุด (สำหรับ Flutter ทำ Polling เช็กให้เด้งจอ Alert อัตโนมัติ)
Route::get('/driver-latest-alert', function (Request $request) {
    $driverId = $request->query('driver_id');
    if (!$driverId) {
        return response()->json(['success' => false, 'message' => 'Missing driver_id'], 400);
    }
    $latestAlert = Alert::where('driver_id', $driverId)
        ->orderBy('timestamp', 'desc')
        ->first();
    return response()->json(['success' => true, 'data' => $latestAlert], 200);
});
