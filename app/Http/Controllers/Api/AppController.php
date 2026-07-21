<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceSetting;
use App\Models\Driver;
use App\Models\DriverDevice;
use App\Models\Notification;
use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppController extends Controller
{
    public function showDriver(string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        $driverModel = Driver::with(['activeDevices.setting'])
            ->withCount('alerts')
            ->findOrFail($driver);

        return response()->json(['success' => true, 'data' => $driverModel]);
    }

    public function updateDriver(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        $driverModel = Driver::findOrFail($driver);

        $request->validate([
            'name'       => 'sometimes|string|max:100',
            'avatar_url' => 'sometimes|nullable|string',
            'status'     => 'sometimes|integer|in:0,1',
        ]);

        $driverModel->update($request->only(['name', 'avatar_url', 'status']));

        return response()->json(['success' => true, 'data' => $driverModel]);
    }

    public function devices(string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        $driverModel = Driver::findOrFail($driver);

        // ต้องตรงกับ threshold เดิมใน DeviceController::markStaleDevicesOffline()
        $timeoutSeconds = 5;

        $devices = $driverModel->activeDevices()
            ->with('setting')
            ->get()
            ->map(function ($device) use ($timeoutSeconds) {
                // ✅ คำนวณสถานะออนไลน์/ออฟไลน์แบบสดจาก last_heartbeat_at ตรงนี้เลย
                // แทนที่จะเชื่อ column `status` ที่ถูกต้องพึ่ง Laravel Scheduler
                // มาคอยอัปเดตให้เท่านั้น (ถ้า scheduler ไม่ได้รันอยู่จริง เช่นตอน
                // dev ใช้ `php artisan serve` เฉยๆ โดยไม่มี `schedule:work`/cron คู่ไปด้วย
                // column status จะค้างเป็น 'ออนไลน์' แม้ heartbeat หยุดส่งมาแล้วจริงๆ)
                $isStale = !$device->last_heartbeat_at
                    || $device->last_heartbeat_at->lt(now()->subSeconds($timeoutSeconds));

                $device->status = $isStale ? 'ออฟไลน์' : 'ออนไลน์';

                return $device;
            });

        return response()->json(['success' => true, 'data' => $devices]);
    }

    public function storeDevice(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'device_id'     => 'sometimes|required|string|max:11|unique:devices,device_id',
            'serial_number' => 'required|string|max:50|unique:devices,serial_number',
            'device_name'   => 'required|string|max:100',
            'device_type'   => 'required|string|max:100',
            'status'        => 'sometimes|string|max:20',
            'is_active'     => 'sometimes|boolean',
        ]);

        $device = DB::transaction(function () use ($request, $driver) {
            $deviceData = $request->only([
                'serial_number',
                'device_name',
                'device_type',
                'status',
                'is_active',
            ]);

            // สร้าง device_id ถ้าไม่มี
            if ($request->has('device_id')) {
                $deviceData['device_id'] = $request->device_id;
            } else {
                $deviceData['device_id'] = (string) \Illuminate\Support\Str::random(11);
            }

            $device = Device::create($deviceData);

            $driverDeviceData = [
                'driver_id'   => $driver,
                'device_id'   => $device->device_id,
                'assigned_at' => now(),
                'is_active'   => true,
            ];

            // สร้าง driver_device_id
            $driverDeviceData['driver_device_id'] = (string) \Illuminate\Support\Str::random(11);

            DriverDevice::create($driverDeviceData);

            return $device->load('setting');
        });

        return response()->json(['success' => true, 'data' => $device], 201);
    }

    public function showDevice(string $driver, string $device)
    {
        // เปลี่ยน $driver และ $device เป็น string type
        $deviceModel = $this->activeDeviceForDriver($driver, $device)->load('setting');

        return response()->json(['success' => true, 'data' => $deviceModel]);
    }

    public function updateDevice(Request $request, string $driver, string $device)
    {
        // เปลี่ยน $driver และ $device เป็น string type
        $deviceModel = $this->activeDeviceForDriver($driver, $device);

        $request->validate([
            'serial_number' => 'sometimes|string|max:50|unique:devices,serial_number,' . $device . ',device_id',
            'device_name'   => 'sometimes|string|max:100',
            'device_type'   => 'sometimes|string|max:100',
            'status'        => 'sometimes|string|max:20',
            'is_active'     => 'sometimes|boolean',
        ]);

        $deviceModel->update($request->only([
            'serial_number',
            'device_name',
            'device_type',
            'status',
            'is_active',
        ]));

        return response()->json(['success' => true, 'data' => $deviceModel->load('setting')]);
    }

    public function destroyDevice(string $driver, string $device)
    {
        // เปลี่ยน $driver และ $device เป็น string type
        $assignment = $this->activeAssignment($driver, $device);

        $assignment->update([
            'is_active'     => false,
            'unassigned_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Device removed from driver']);
    }

    public function deviceSetting(string $driver, string $device)
    {
        // เปลี่ยน $driver และ $device เป็น string type
        $deviceModel = $this->activeDeviceForDriver($driver, $device);

        return response()->json([
            'success' => true,
            'data'    => $deviceModel->setting,
        ]);
    }

    public function upsertDeviceSetting(Request $request, string $driver, string $device)
    {
        // เปลี่ยน $driver และ $device เป็น string type
        $this->activeDeviceForDriver($driver, $device);

        $request->validate([
            'setting_id'    => 'sometimes|required|string|max:11|unique:device_settings,setting_id',
            'volume_level'  => 'sometimes|integer|min:0|max:100',
            'sound_enabled' => 'sometimes|boolean',
            'active_tone'   => 'sometimes|string|max:50',
        ]);

        $setting = DeviceSetting::firstOrNew(['device_id' => $device]);

        // สร้าง setting_id ถ้าไม่มี
        if (!$setting->exists && $request->has('setting_id')) {
            $setting->setting_id = $request->setting_id;
        } elseif (!$setting->exists) {
            $setting->setting_id = (string) \Illuminate\Support\Str::random(11);
        }

        $setting->fill($request->only(['volume_level', 'sound_enabled', 'active_tone']));
        $setting->save();

        return response()->json(['success' => true, 'data' => $setting]);
    }

    public function trips(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'device_id' => 'sometimes|string|exists:devices,device_id',
            'status'    => 'sometimes|in:active,completed',
        ]);

        if ($request->filled('device_id')) {
            $this->activeDeviceForDriver($driver, $request->device_id);
        }

        $trips = Trip::with(['device'])
            ->where('driver_id', $driver)
            ->when($request->filled('device_id'), fn ($query) => $query->where('device_id', $request->device_id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->orderBy('start_time', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function storeTrip(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'trip_id'    => 'sometimes|required|string|max:11|unique:trips,trip_id',
            'device_id'  => 'nullable|string|exists:devices,device_id',
            'start_time' => 'required|date',
            'end_time'   => 'sometimes|nullable|date',
            'duration'   => 'sometimes|nullable|string|max:50',
            'distance'   => 'sometimes|numeric|min:0',
            'status'     => 'sometimes|in:active,completed',
        ]);

        if ($request->filled('device_id')) {
            $this->activeDeviceForDriver($driver, $request->device_id);
        }

        $tripData = [
            'driver_id'  => $driver,
            'device_id'  => $request->device_id,
            'start_time' => $request->start_time,
            'end_time'   => $request->end_time,
            'duration'   => $request->duration,
            'distance'   => $request->distance ?? 0,
            'status'     => $request->status ?? 'active',
        ];

        // สร้าง trip_id ถ้าไม่มี
        if ($request->has('trip_id')) {
            $tripData['trip_id'] = $request->trip_id;
        } else {
            $tripData['trip_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $trip = Trip::create($tripData);

        return response()->json(['success' => true, 'data' => $trip->load('device')], 201);
    }

    public function showTrip(string $driver, string $trip)
    {
        // เปลี่ยน $driver และ $trip เป็น string type
        return response()->json([
            'success' => true,
            'data'    => $this->tripForDriver($driver, $trip)->load(['device', 'locations', 'alerts']),
        ]);
    }

    public function updateTrip(Request $request, string $driver, string $trip)
    {
        // เปลี่ยน $driver และ $trip เป็น string type
        $tripModel = $this->tripForDriver($driver, $trip);

        $request->validate([
            'device_id'  => 'sometimes|nullable|string|exists:devices,device_id',
            'end_time'   => 'sometimes|nullable|date',
            'duration'   => 'sometimes|nullable|string|max:50',
            'distance'   => 'sometimes|numeric|min:0',
            'status'     => 'sometimes|in:active,completed',
        ]);

        if ($request->filled('device_id')) {
            $this->activeDeviceForDriver($driver, $request->device_id);
        }

        $tripModel->update($request->only([
            'device_id',
            'end_time',
            'duration',
            'distance',
            'status',
        ]));

        return response()->json(['success' => true, 'data' => $tripModel->load(['device', 'locations'])]);
    }

    public function destroyTrip(string $driver, string $trip)
    {
        // เปลี่ยน $driver และ $trip เป็น string type
        $this->tripForDriver($driver, $trip)->delete();

        return response()->json(['success' => true, 'message' => 'Trip deleted']);
    }

    public function locations(Request $request, string $driver, string $trip)
    {
        // เปลี่ยน $driver และ $trip เป็น string type
        $this->tripForDriver($driver, $trip);

        $request->validate([
            'is_precise' => 'sometimes|boolean',
        ]);

        $locations = TripLocation::where('trip_id', $trip)
            ->when($request->has('is_precise'), fn ($query) => $query->where('is_precise', $request->boolean('is_precise')))
            ->orderBy('recorded_at')
            ->get();

        return response()->json(['success' => true, 'data' => $locations]);
    }

    public function storeLocation(Request $request, string $driver, string $trip)
    {
        // เปลี่ยน $driver และ $trip เป็น string type
        $this->tripForDriver($driver, $trip);

        $request->validate([
            'location_id' => 'sometimes|required|string|max:11|unique:trip_locations,location_id',
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            'speed'       => 'nullable|numeric',
        ]);

        $locationData = [
            'trip_id'     => $trip,
            'latitude'    => $request->latitude,
            'longitude'   => $request->longitude,
            'speed'       => $request->speed,
            'recorded_at' => now(),
        ];

        // สร้าง location_id ถ้าไม่มี
        if ($request->has('location_id')) {
            $locationData['location_id'] = $request->location_id;
        } else {
            $locationData['location_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $location = TripLocation::create($locationData);

        return response()->json(['success' => true, 'data' => $location], 201);
    }

    public function showLocation(string $driver, string $trip, string $location)
    {
        // เปลี่ยน $driver, $trip, $location เป็น string type
        return response()->json([
            'success' => true,
            'data'    => $this->locationForTrip($trip, $location),
        ]);
    }

    public function updateLocation(Request $request, string $driver, string $trip, string $location)
    {
        // เปลี่ยน $driver, $trip, $location เป็น string type
        $locationModel = $this->locationForTrip($trip, $location);

        $request->validate([
            'latitude'  => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'speed'     => 'sometimes|nullable|numeric',
        ]);

        $locationModel->update($request->only(['latitude', 'longitude', 'speed']));

        return response()->json(['success' => true, 'data' => $locationModel]);
    }

    public function destroyLocation(string $driver, string $trip, string $location)
    {
        // เปลี่ยน $driver, $trip, $location เป็น string type
        $this->locationForTrip($trip, $location)->delete();

        return response()->json(['success' => true, 'message' => 'Location deleted']);
    }

    public function alerts(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'trip_id'  => 'sometimes|string|exists:trips,trip_id',
            'device_id' => 'sometimes|string|exists:devices,device_id',
            'type'     => 'sometimes|string|max:50',
        ]);

        if ($request->filled('trip_id')) {
            $this->tripForDriver($driver, $request->trip_id);
        }

        if ($request->filled('device_id')) {
            $this->activeDeviceForDriver($driver, $request->device_id);
        }

        $alerts = Alert::with(['trip', 'driver'])
            ->where('driver_id', $driver)
            ->when($request->filled('trip_id'), fn ($query) => $query->where('trip_id', $request->trip_id))
            ->when($request->filled('device_id'), fn ($query) => $query->where('device_id', $request->device_id))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->type))
            ->orderBy('timestamp', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $alerts]);
    }

    public function storeAlert(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'alert_id'     => 'sometimes|required|string|max:11|unique:alerts,alert_id',
            'trip_id'      => 'required|string|exists:trips,trip_id',
            'device_id'    => 'nullable|string|exists:devices,device_id',
            'type'         => 'required|string|max:50',
            'snapshot_url' => 'nullable|url',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
        ]);

        $trip = $this->tripForDriver($driver, $request->trip_id);
        $deviceId = $request->device_id ?? $trip->device_id;

        if ($deviceId) {
            $this->activeDeviceForDriver($driver, $deviceId);
        }

        $alertData = [
            'trip_id'      => $request->trip_id,
            'driver_id'    => $driver,
            'device_id'    => $deviceId,
            'type'         => $request->type,
            'snapshot_url' => $request->snapshot_url,
            'latitude'     => $request->latitude,
            'longitude'    => $request->longitude,
        ];

        // สร้าง alert_id ถ้าไม่มี
        if ($request->has('alert_id')) {
            $alertData['alert_id'] = $request->alert_id;
        } else {
            $alertData['alert_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $alert = Alert::create($alertData);

        return response()->json(['success' => true, 'data' => $alert], 201);
    }

    public function showAlert(string $driver, string $alert)
    {
        // เปลี่ยน $driver และ $alert เป็น string type
        return response()->json([
            'success' => true,
            'data'    => $this->alertForDriver($driver, $alert)->load(['trip', 'driver']),
        ]);
    }

    public function updateAlert(Request $request, string $driver, string $alert)
    {
        // เปลี่ยน $driver และ $alert เป็น string type
        $alertModel = $this->alertForDriver($driver, $alert);

        $request->validate([
            'trip_id'      => 'sometimes|string|exists:trips,trip_id',
            'device_id'    => 'sometimes|nullable|string|exists:devices,device_id',
            'type'         => 'sometimes|string|max:50',
            'snapshot_url' => 'sometimes|nullable|url',
            'latitude'     => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'    => 'sometimes|nullable|numeric|between:-180,180',
        ]);

        if ($request->filled('trip_id')) {
            $this->tripForDriver($driver, $request->trip_id);
        }

        if ($request->filled('device_id')) {
            $this->activeDeviceForDriver($driver, $request->device_id);
        }

        $alertModel->update($request->only([
            'trip_id',
            'device_id',
            'type',
            'snapshot_url',
            'latitude',
            'longitude',
        ]));

        return response()->json(['success' => true, 'data' => $alertModel]);
    }

    public function destroyAlert(string $driver, string $alert)
    {
        // เปลี่ยน $driver และ $alert เป็น string type
        $this->alertForDriver($driver, $alert)->delete();

        return response()->json(['success' => true, 'message' => 'Alert deleted']);
    }

    public function notifications(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'is_read' => 'sometimes|boolean',
        ]);

        $notifications = Notification::with('alert')
            ->where('driver_id', $driver)
            ->when($request->has('is_read'), fn ($query) => $query->where('is_read', $request->boolean('is_read')))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $notifications]);
    }

    public function storeNotification(Request $request, string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        $request->validate([
            'noti_id'  => 'sometimes|required|string|max:11|unique:notifications,noti_id',
            'alert_id' => 'required|string|exists:alerts,alert_id',
            'message'  => 'required|string|max:500',
            'is_read'  => 'sometimes|boolean',
        ]);

        $this->alertForDriver($driver, $request->alert_id);

        $notificationData = [
            'driver_id' => $driver,
            'alert_id'  => $request->alert_id,
            'message'   => $request->message,
            'is_read'   => $request->boolean('is_read'),
        ];

        // สร้าง noti_id ถ้าไม่มี
        if ($request->has('noti_id')) {
            $notificationData['noti_id'] = $request->noti_id;
        } else {
            $notificationData['noti_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $notification = Notification::create($notificationData);

        return response()->json(['success' => true, 'data' => $notification], 201);
    }

    public function showNotification(string $driver, string $notification)
    {
        // เปลี่ยน $driver และ $notification เป็น string type
        return response()->json([
            'success' => true,
            'data'    => $this->notificationForDriver($driver, $notification)->load('alert'),
        ]);
    }

    public function updateNotification(Request $request, string $driver, string $notification)
    {
        // เปลี่ยน $driver และ $notification เป็น string type
        $notificationModel = $this->notificationForDriver($driver, $notification);

        $request->validate([
            'message' => 'sometimes|string|max:500',
            'is_read' => 'sometimes|boolean',
        ]);

        $notificationModel->update($request->only(['message', 'is_read']));

        return response()->json(['success' => true, 'data' => $notificationModel]);
    }

    public function markNotificationRead(string $driver, string $notification)
    {
        // เปลี่ยน $driver และ $notification เป็น string type
        $notificationModel = $this->notificationForDriver($driver, $notification);
        $notificationModel->update(['is_read' => true]);

        return response()->json(['success' => true, 'data' => $notificationModel]);
    }

    public function markAllNotificationsRead(string $driver)
    {
        // เปลี่ยน $driver เป็น string type
        Driver::findOrFail($driver);

        Notification::where('driver_id', $driver)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true, 'message' => 'Notifications marked as read']);
    }

    public function destroyNotification(string $driver, string $notification)
    {
        // เปลี่ยน $driver และ $notification เป็น string type
        $this->notificationForDriver($driver, $notification)->delete();

        return response()->json(['success' => true, 'message' => 'Notification deleted']);
    }

    private function activeDeviceForDriver(string $driver, string $device): Device
    {
        // เปลี่ยน type hints เป็น string
        return Device::where('device_id', $device)
            ->whereHas('driverDevices', function ($query) use ($driver) {
                $query->where('driver_id', $driver)
                    ->where('is_active', true)
                    ->whereNull('unassigned_at');
            })
            ->firstOrFail();
    }

    private function activeAssignment(string $driver, string $device): DriverDevice
    {
        // เปลี่ยน type hints เป็น string
        return DriverDevice::where('driver_id', $driver)
            ->where('device_id', $device)
            ->where('is_active', true)
            ->whereNull('unassigned_at')
            ->firstOrFail();
    }

    private function tripForDriver(string $driver, string $trip): Trip
    {
        // เปลี่ยน type hints เป็น string
        return Trip::where('driver_id', $driver)
            ->where('trip_id', $trip)
            ->firstOrFail();
    }

    private function locationForTrip(string $trip, string $location): TripLocation
    {
        // เปลี่ยน type hints เป็น string
        return TripLocation::where('trip_id', $trip)
            ->where('location_id', $location)
            ->firstOrFail();
    }

    private function alertForDriver(string $driver, string $alert): Alert
    {
        // เปลี่ยน type hints เป็น string
        return Alert::where('driver_id', $driver)
            ->where('alert_id', $alert)
            ->firstOrFail();
    }

    private function notificationForDriver(string $driver, string $notification): Notification
    {
        // เปลี่ยน type hints เป็น string
        return Notification::where('driver_id', $driver)
            ->where('noti_id', $notification)
            ->firstOrFail();
    }
}
