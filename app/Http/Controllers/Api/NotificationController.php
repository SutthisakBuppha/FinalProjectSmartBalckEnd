<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::with(['driver', 'alert'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $notifications]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'noti_id'   => 'sometimes|required|string|max:11|unique:notifications,noti_id',
            'driver_id' => 'required|string|exists:drivers,driver_id',
            'alert_id'  => 'required|string|exists:alerts,alert_id',
            'message'   => 'required|string|max:500',
        ]);

        $data = [
            'driver_id' => $request->driver_id,
            'alert_id'  => $request->alert_id,
            'message'   => $request->message,
            'is_read'   => false,
        ];

        // ถ้าไม่มี noti_id ให้สร้างเป็น string random
        if ($request->has('noti_id')) {
            $data['noti_id'] = $request->noti_id;
        } else {
            $data['noti_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $notification = Notification::create($data);

        return response()->json([
            'success' => true,
            'data'    => $notification->load(['driver', 'alert'])
        ], 201);
    }

    public function show(string $id)
    {
        // เปลี่ยน $id เป็น string type เนื่องจากเป็น char ใน database แล้ว
        $notification = Notification::with(['driver', 'alert'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $notification]);
    }

    // ทำเครื่องหมายว่าอ่านแล้ว (1 รายการ)
    public function markAsRead(string $id)
    {
        // เปลี่ยน $id เป็น string type
        $notification = Notification::findOrFail($id);
        $notification->update(['is_read' => true]);

        return response()->json(['success' => true, 'data' => $notification]);
    }

    // ทำเครื่องหมายว่าอ่านทั้งหมด
    public function markAllAsRead()
    {
        Notification::where('is_read', false)->update(['is_read' => true]);

        return response()->json(['success' => true, 'message' => 'อ่านทั้งหมดแล้ว']);
    }

    public function update(Request $request, string $id)
    {
        // เปลี่ยน $id เป็น string type
        $notification = Notification::findOrFail($id);

        $request->validate([
            'is_read' => 'sometimes|boolean',
            'message' => 'sometimes|string|max:500',
        ]);

        $notification->update($request->all());

        return response()->json(['success' => true, 'data' => $notification]);
    }

    public function destroy(string $id)
    {
        // เปลี่ยน $id เป็น string type
        Notification::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    }
}
