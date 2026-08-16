<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Notification;
use App\Services\FcmService; // <-- Import
use Illuminate\Http\Request;

class AlertController extends Controller
{
    protected $fcmService;

    public function __construct(FcmService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function store(Request $request)
    {
        $request->validate([
            'alert_id'     => 'sometimes|required|string|max:8|unique:alerts,alert_id',
            'trip_id'      => 'nullable|string|exists:trips,trip_id',
            'driver_id'    => 'required|string|exists:drivers,driver_id',
            'device_id'    => 'nullable|string|exists:devices,device_id',
            'type'         => 'required|in:ง่วงนอน,ไม่มองถนน,ไม่กระพริบตาเป็นเวลานาน',
            'snapshot_url' => 'nullable|url',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
        ]);

        $data = $request->only([
            'alert_id', 'trip_id', 'driver_id', 'device_id',
            'type', 'snapshot_url', 'latitude', 'longitude',
        ]);

        if (!isset($data['alert_id'])) {
            $data['alert_id'] = (string) \Illuminate\Support\Str::random(8);
        }

        $alert = Alert::create($data);

        // 1. ยิง Push Notification ทันทีที่เกิด Alert
        $this->fcmService->sendPushToDriver(
            $alert->driver_id,
            "แจ้งเตือน: {$alert->type}",
            "ตรวจพบพฤติกรรมความเสี่ยงขณะขับขี่ ({$alert->type})",
            [
                'device_id' => (string) $alert->device_id,
                'alert_id'  => (string) $alert->alert_id,
                'type'      => $alert->type,
            ]
        );

        // 2. ตรวจสอบพฤติกรรมซ้ำใน 10 นาที
        $recentCount = Alert::where('trip_id', $alert->trip_id)
            ->where('type', $alert->type)
            ->where('timestamp', '>=', now()->subMinutes(10))
            ->count();

        if ($recentCount >= 3) {
            $alreadyNotified = Notification::whereHas('alert', function ($q) use ($alert) {
                    $q->where('trip_id', $alert->trip_id)->where('type', $alert->type);
                })
                ->where('created_at', '>=', now()->subSeconds(5))
                ->exists();

            if (!$alreadyNotified) {
                $msg = "ตรวจพบพฤติกรรม{$alert->type}ซ้ำ {$recentCount} ครั้ง กรุณาหาที่พักรถที่ใกล้ที่สุดโดยด่วน";
                Notification::create([
                    'driver_id' => $alert->driver_id,
                    'alert_id'  => $alert->alert_id,
                    'message'   => $msg,
                    'is_read'   => false,
                ]);

                // ส่ง Push Notification สำหรับการเตือนระดับวิกฤตซ้ำ
                $this->fcmService->sendPushToDriver(
                    $alert->driver_id,
                    "🚨 เตือนภัยระดับวิกฤต!",
                    $msg,
                    ['device_id' => (string) $alert->device_id, 'alert_id' => (string) $alert->alert_id]
                );
            }
        }

        return response()->json([
            'success'  => true,
            'data'     => $alert,
            'alert_id' => $alert->alert_id
        ], 201);
    }
}