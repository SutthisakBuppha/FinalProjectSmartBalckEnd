<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function index()
    {
        $drivers = Driver::withCount('alerts')
            ->with('devices')
            ->get();
        return response()->json(['success' => true, 'data' => $drivers]);
    }

    public function store(Request $request)
    {
        $request->validate([
            // driver_id เป็น char แล้ว - ไม่เป็น auto increment
            'driver_id'  => 'sometimes|required|string|max:11|unique:drivers,driver_id',
            'username'   => 'nullable|required_with:password|string|max:100|unique:drivers,username',
            'password'   => 'nullable|required_with:username|string|min:8',
            'name'       => 'required|string|max:100',
            'avatar_url' => 'sometimes|nullable|string',
            'status'     => 'required|in:ปฏิบัติงานปกติ,กำลังออกรถ,ลาพักร้อน,ระงับการขับขี่',
        ]);

        $data = $request->only([
            'driver_id',
            'username',
            'password',
            'name',
            'avatar_url',
            'status',
        ]);

        // ถ้าไม่มี driver_id ให้สร้างเป็น string random
        if (!isset($data['driver_id'])) {
            $data['driver_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $driver = Driver::create($data);

        return response()->json(['success' => true, 'data' => $driver], 201);
    }

    public function show(string $id)
    {
        // เปลี่ยน $id เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $driver = Driver::with(['trips', 'alerts', 'devices'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $driver]);
    }

    public function update(Request $request, string $id)
    {
        // เปลี่ยน $id เป็น string type
        $driver = Driver::findOrFail($id);

        $request->validate([
            'username'   => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('drivers', 'username')->ignore($driver->driver_id, 'driver_id'),
            ],
            'password'   => 'sometimes|nullable|string|min:8',
            'name'       => 'sometimes|string|max:100',
            'avatar_url' => 'sometimes|nullable|string',
            'status'     => 'sometimes|in:ปฏิบัติงานปกติ,กำลังออกรถ,ลาพักร้อน,ระงับการขับขี่',
        ]);

        $data = $request->only(['username', 'name', 'avatar_url', 'status']);

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $driver->update($data);

        return response()->json(['success' => true, 'data' => $driver->fresh()]);
    }

    public function destroy(string $id)
    {
        // เปลี่ยน $id เป็น string type
        Driver::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    }
}
