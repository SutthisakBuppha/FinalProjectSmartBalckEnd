<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripLocation;
use App\Models\Trip;
use Illuminate\Http\Request;

class TripLocationController extends Controller
{
    public function index(string $tripId)
    {
        // เปลี่ยน $tripId เป็น string type เนื่องจากเป็น char ใน database แล้ว
        Trip::findOrFail($tripId);
        $locations = TripLocation::where('trip_id', $tripId)
            ->orderBy('recorded_at')
            ->get();

        return response()->json(['success' => true, 'data' => $locations]);
    }

    public function store(Request $request, string $tripId)
    {
        // เปลี่ยน $tripId เป็น string type
        Trip::findOrFail($tripId);

        $request->validate([
            'location_id' => 'sometimes|required|string|max:11|unique:trip_locations,location_id',
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            'speed'       => 'nullable|numeric',
        ]);

        $data = [
            'trip_id'     => $tripId,
            'latitude'    => $request->latitude,
            'longitude'   => $request->longitude,
            'speed'       => $request->speed,
            'recorded_at' => now(),
        ];

        // ถ้าไม่มี location_id ให้สร้างเป็น string random
        if ($request->has('location_id')) {
            $data['location_id'] = $request->location_id;
        } else {
            $data['location_id'] = (string) \Illuminate\Support\Str::random(11);
        }

        $location = TripLocation::create($data);

        return response()->json(['success' => true, 'data' => $location], 201);
    }
}
