<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Notification;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index()
    {
        $alerts = Alert::with(['driver', 'trip'])
            ->orderBy('timestamp', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $alerts]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'alert_id'     => 'sometimes|required|string|max:11|unique:alerts,alert_id',
            'trip_id'      => 'required|string|exists:trips,trip_id',
            'driver_id'    => 'required|string|exists:drivers,driver_id',
            'device_id'    => 'nullable|string|exists:devices,device_id',
            'type'         => 'required|in:ง่วงนอน,ใช้โทรศัพท์,เสียสมาธิ,เหม่อลอย,หาว',
            'snapshot_url' => 'nullable|url',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
        ]);

        $data = $request->only([
            'alert_id',
            'trip_id', 'driver_id', 'device_id',
            'type', 'snapshot_url', 'latitude', 'longitude',
        ]);

        // ถ้าไม่มี alert_id ให้สร้างเป็น string random
        if (!isset($data['alert_id'])) {
            $data['alert_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $alert = Alert::create($data);

        // 📌 LOGIC: นับจำนวนครั้งว่าพฤติกรรมนี้เกิดซ้ำในทริปนี้กี่ครั้ง ในช่วง 10 นาทีที่ผ่านมา
        $recentCount = Alert::where('trip_id', $alert->trip_id)
            ->where('type', $alert->type)
            ->where('timestamp', '>=', now()->subMinutes(10))
            ->count();

        // 📌 ถ้าครบ 3 ครั้ง ให้สร้าง Notification
        if ($recentCount >= 3) {
            $alreadyNotified = Notification::whereHas('alert', function ($q) use ($alert) {
                    $q->where('trip_id', $alert->trip_id)
                      ->where('type', $alert->type);
                })
                ->where('created_at', '>=', now()->subSeconds(5))
                ->exists();

            if (!$alreadyNotified) {
                Notification::create([
                    'driver_id' => $alert->driver_id,
                    'alert_id'  => $alert->alert_id,
                    'message'   => "ตรวจพบพฤติกรรม{$alert->type}ซ้ำ {$recentCount} ครั้ง กรุณาหาที่พักรถที่ใกล้ที่สุดโดยด่วน",
                    'is_read'   => false,
                ]);
            }
        }

        error_log("🚨 [EMERGENCY] บันทึก Alert ID: " . $alert->alert_id . " เวลา: " . now());

        return response()->json([
            'success'  => true,
            'data'     => $alert,
            'alert_id' => $alert->alert_id
        ], 201);
    }

    public function show(string $id)
    {
        // เปลี่ยน $id เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $alert = Alert::with(['driver', 'trip'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $alert]);
    }

    public function update(Request $request, string $id)
    {
        // เปลี่ยน $id เป็น string type
        $alert = Alert::findOrFail($id);

        $request->validate([
            'trip_id'      => 'sometimes|string|exists:trips,trip_id',
            'driver_id'    => 'sometimes|string|exists:drivers,driver_id',
            'device_id'    => 'sometimes|nullable|string|exists:devices,device_id',
            'type'         => 'sometimes|in:ง่วงนอน,ใช้โทรศัพท์,เสียสมาธิ,เหม่อลอย,หาว',
            'snapshot_url' => 'sometimes|nullable|url',
            'latitude'     => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'    => 'sometimes|nullable|numeric|between:-180,180',
        ]);

        $alert->update($request->only([
            'trip_id', 'driver_id', 'device_id',
            'type', 'snapshot_url', 'latitude', 'longitude',
        ]));

        return response()->json(['success' => true, 'data' => $alert]);
    }

    public function destroy(string $id)
    {
        // เปลี่ยน $id เป็น string type
        Alert::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    }
}
