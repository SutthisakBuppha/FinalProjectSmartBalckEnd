<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function index()
    {
        $trips = Trip::with(['driver', 'device'])->get();
        return response()->json(['success' => true, 'data' => $trips]);
    }

    /**
     * ดึงข้อมูลการเดินทางเฉพาะของ Driver คนที่ระบุตามระบบ Auth
     */
    public function driverTrips(Request $request, string $driverId)
    {
        // เปลี่ยน $driverId เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $status = $request->query('status');

        $query = Trip::where('driver_id', $driverId)
            ->with(['device'])
            ->withCount('alerts') // นับจำนวนการแจ้งเตือนในทริปนั้นๆ (ส่งเป็น alerts_count ไปให้ Flutter)
            ->orderBy('start_time', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $trips = $query->get();

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id'    => 'sometimes|required|string|max:11|unique:trips,trip_id',
            'driver_id'  => 'required|exists:drivers,driver_id',
            'device_id'  => 'nullable|exists:devices,device_id',
            'start_time' => 'required|date',
        ]);

        $data = $request->only([
            'trip_id',
            'driver_id',
            'device_id',
            'start_time',
        ]);

        // ถ้าไม่มี trip_id ให้สร้างเป็น string random
        if (!isset($data['trip_id'])) {
            $data['trip_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $trip = Trip::create($data);

        return response()->json(['success' => true, 'data' => $trip], 201);
    }

    public function show(string $id)
    {
        // เปลี่ยน $id เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $trip = Trip::with(['driver', 'device'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $trip]);
    }

    public function update(Request $request, string $id)
    {
        // เปลี่ยน $id เป็น string type
        $trip = Trip::findOrFail($id);

        $request->validate([
            'end_location' => 'sometimes|string',
            'end_time'     => 'sometimes|date',
            'status'       => 'sometimes|in:active,completed',
        ]);

        $trip->update($request->only([
            'end_location',
            'end_time',
            'status',
        ]));

        return response()->json(['success' => true, 'data' => $trip]);
    }

    public function destroy(string $id)
    {
        // เปลี่ยน $id เป็น string type
        Trip::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    }
}
