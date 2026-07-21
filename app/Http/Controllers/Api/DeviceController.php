<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    // สถานะที่ระบบอนุญาตให้ใช้ได้เท่านั้น (ตัด 'ว่าง' และ 'ส่งซ่อม' ออกแล้ว)
    private const ALLOWED_STATUSES = ['ออนไลน์', 'ออฟไลน์'];

    // ==========================================
    // 1. ระบบ CRUD อุปกรณ์หลัก (สำหรับ Admin Panel)
    // ==========================================

    public function index()
    {
        $timeoutSeconds = 60; // ต้องตรงกับ threshold ใน markStaleDevicesOffline()

        $devices = Device::orderBy('created_at', 'desc')
            ->get()
            ->map(function ($device) use ($timeoutSeconds) {
                // ✅ คำนวณสถานะแบบสดจาก last_heartbeat_at เช่นเดียวกับ
                // AppController::devices() แทนการเชื่อ column status เดิม
                $isStale = !$device->last_heartbeat_at
                    || $device->last_heartbeat_at->lt(now()->subSeconds($timeoutSeconds));

                $device->status = $isStale ? 'ออฟไลน์' : 'ออนไลน์';

                return $device;
            });

        return response()->json([
            'success' => true,
            'data' => $devices
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'device_id'     => 'sometimes|required|string|max:11|unique:devices,device_id',
            'serial_number' => 'required|string|unique:devices,serial_number',
            'name'          => 'required|string',
            'status'        => 'nullable|in:' . implode(',', self::ALLOWED_STATUSES),
        ]);

        // ถ้าไม่ส่ง status มา ให้ตั้งค่าเริ่มต้นเป็นออฟไลน์ (แทน default เดิมที่เคยเป็น 'ว่าง')
        $data['status'] = $data['status'] ?? 'ออฟไลน์';

        // ถ้าไม่มี device_id ให้สร้างเป็น string random
        if (!isset($data['device_id'])) {
            $data['device_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $device = Device::create($data);
        return response()->json(['success' => true, 'data' => $device], 201);
    }

    public function show(string $id)
    {
        // เปลี่ยน $id เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $device = Device::findOrFail($id);
        return response()->json(['success' => true, 'data' => $device]);
    }

    public function update(Request $request, string $id)
    {
        // เปลี่ยน $id เป็น string type
        $device = Device::findOrFail($id);
        $data = $request->validate([
            'name'   => 'sometimes|required|string',
            'status' => 'sometimes|required|in:' . implode(',', self::ALLOWED_STATUSES),
        ]);

        $device->update($data);
        return response()->json(['success' => true, 'data' => $device]);
    }

    public function destroy(string $id)
    {
        // เปลี่ยน $id เป็น string type
        $device = Device::findOrFail($id);
        $device->delete();
        return response()->json(['success' => true, 'message' => 'ลบอุปกรณ์เรียบร้อยแล้ว']);
    }

    // ==========================================
    // 2. ระบบ IoT & Heartbeat (สำหรับบอร์ด ESP32)
    // ==========================================

    public function heartbeat(Request $request)
    {
        $request->validate([
            'serial_number' => 'required|string',
        ]);

        $device = Device::where('serial_number', $request->serial_number)->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบอุปกรณ์นี้ในระบบ (ยังไม่ได้ลงทะเบียน)',
            ], 404);
        }

        $device->last_heartbeat_at = now();
        // ไม่มีสถานะ 'ส่งซ่อม' แยกอีกต่อไป -> heartbeat เข้ามาเมื่อไหร่ถือว่าออนไลน์เสมอ
        $device->status = 'ออนไลน์';
        $device->last_active = 'เพิ่งใช้งาน';
        $device->save();

        return response()->json(['success' => true]);
    }

    public static function markStaleDevicesOffline(): void
    {
        // ปรับเป็น 60 วินาที เพื่อให้สัมพันธ์กับรอบการรนของ Laravel Scheduler (รันทุกๆ 1 นาที)
        $timeoutSeconds = 60;

        Device::where('status', 'ออนไลน์')
            ->where(function ($query) use ($timeoutSeconds) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subSeconds($timeoutSeconds));
            })
            ->update([
                'status'      => 'ออฟไลน์',
                'last_active' => now()->format('d/m/Y H:i'),
            ]);
    }

    // ==========================================
    // 3. ระบบจัดการไฟล์สื่อ (สำหรับแอปพลิเคชัน Flutter)
    // ==========================================

    public function uploadMedia(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string|exists:devices,device_id',
            'type'      => 'required|in:image,video,audio', // เพิ่ม audio ให้ตรงกับที่ Flutter ส่งมา
            'file'      => 'required|file|max:51200', // จำกัด 50MB
        ]);

        $deviceId = $request->input('device_id');
        $type = $request->input('type');
        $file = $request->file('file');

        $extension = $file->getClientOriginalExtension();
        $fileName = $type . '_' . now()->format('YmdHis') . '_' . Str::random(6) . '.' . $extension;
    // เสียงจะถูกส่งไปเก็บที่ storage/app/public/devices
        $folder = "devices/{$deviceId}/{$type}s";
        $path = $file->storeAs($folder, $fileName, 'public');

        $url = asset('storage/' . $path);

        $media = DeviceMedia::create([
            'device_id' => $deviceId,
            'type'      => $type,
            'file_name' => $fileName,
            'file_path' => $path,
            'url'       => $url,
            'file_size' => $file->getSize(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'file_name' => $media->file_name,
                'url'       => $media->url,
                'file_size' => $media->file_size,
                'type'      => $media->type,
            ],
        ], 201);
    }

    public function indexMedia(string $deviceId)
    {
        // เปลี่ยน $deviceId เป็น string type
        $items = DeviceMedia::where('device_id', $deviceId)
            ->orderByDesc('created_at')
            ->get(['file_name', 'url', 'file_size', 'type', 'created_at']);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function destroyMedia(string $mediaId)
    {
        // เปลี่ยน $mediaId เป็น string type
        $media = DeviceMedia::findOrFail($mediaId);
        Storage::disk('public')->delete($media->file_path);
        $media->delete();

        return response()->json(['success' => true]);
    }
}
