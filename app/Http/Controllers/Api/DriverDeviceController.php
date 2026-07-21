<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Device;
use App\Models\DriverDevice;
use Illuminate\Http\Request;

class DriverDeviceController extends Controller
{
    // GET /drivers/{driver}/devices
    public function index($driver)
    {
        $driverModel = Driver::with('activeDevices')->findOrFail($driver);

        return response()->json([
            'success' => true,
            'data'    => $driverModel->activeDevices,
        ]);
    }

    // POST /drivers/{driver}/devices/{device}/assign
    public function assign($driver, $device)
    {
        Driver::findOrFail($driver);
        Device::findOrFail($device);

        $exists = DriverDevice::where('device_id', $device)
            ->where('is_active', true)
            ->whereNull('unassigned_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Device is already assigned',
            ], 409);
        }

        $assignment = DriverDevice::create([
            'driver_id'   => $driver,
            'device_id'   => $device,
            'assigned_at' => now(),
            'is_active'   => true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $assignment->load(['driver', 'device']),
        ], 201);
    }

    // PATCH /drivers/{driver}/devices/{device}/unassign
    public function unassign($driver, $device)
    {
        $assignment = DriverDevice::where('driver_id', $driver)
            ->where('device_id', $device)
            ->where('is_active', true)
            ->whereNull('unassigned_at')
            ->firstOrFail();

        $assignment->update([
            'is_active'     => false,
            'unassigned_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Device unassigned',
        ]);
    }
}
