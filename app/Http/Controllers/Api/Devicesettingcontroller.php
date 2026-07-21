<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceSetting;
use Illuminate\Http\Request;

class DeviceSettingController extends Controller
{
    public function index()
    {
        $settings = DeviceSetting::with('device')->get();
        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'setting_id'    => 'sometimes|required|string|max:11|unique:device_settings,setting_id',
            'device_id'     => 'required|string|exists:devices,device_id|unique:device_settings,device_id',
            'volume_level'  => 'sometimes|integer|min:0|max:100',
            'sound_enabled' => 'sometimes|boolean',
            'active_tone'   => 'sometimes|string|max:50',
        ]);

        $data = $request->only([
            'device_id',
            'volume_level',
            'sound_enabled',
            'active_tone',
        ]);

        // ถ้าไม่มี setting_id ให้สร้างเป็น string random
        if ($request->has('setting_id')) {
            $data['setting_id'] = $request->setting_id;
        } else {
            $data['setting_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $setting = DeviceSetting::create($data);

        return response()->json([
            'success' => true,
            'data'    => $setting->load('device')
        ], 201);
    }

    public function show(string $id)
    {
        // เปลี่ยน $id เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $setting = DeviceSetting::with('device')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $setting]);
    }

    public function update(Request $request, string $id)
    {
        // เปลี่ยน $id เป็น string type
        $setting = DeviceSetting::findOrFail($id);

        $request->validate([
            'volume_level'  => 'sometimes|integer|min:0|max:100',
            'sound_enabled' => 'sometimes|boolean',
            'active_tone'   => 'sometimes|string|max:50',
        ]);

        $setting->update($request->only([
            'volume_level',
            'sound_enabled',
            'active_tone',
        ]));

        return response()->json(['success' => true, 'data' => $setting->load('device')]);
    }

    public function destroy(string $id)
    {
        // เปลี่ยน $id เป็น string type
        DeviceSetting::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    }
}
