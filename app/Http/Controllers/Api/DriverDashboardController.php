<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Driver;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverDashboardController extends Controller
{
    /**
     * GET /api/drivers/{id}/dashboard
     *
     * Returns everything the Flutter HomeScreen needs in a single request:
     *   - today's trip & alert counts
     *   - alert type breakdown for today
     *   - current active trip (if any)
     *   - last 5 alerts
     *   - connected device status
     */
    public function index($driver)
    {
        $driverModel = Driver::with([
            'activeDevices' => fn ($q) => $q->orderBy('pivot_assigned_at', 'desc')->limit(1),
        ])->findOrFail($driver);

        $today = now()->toDateString();

        // ── Stats for today ──────────────────────────────────────────────────
        $todayTrips = Trip::where('driver_id', $driver)
            ->whereDate('start_time', $today)
            ->count();

        $todayAlerts = Alert::where('driver_id', $driver)
            ->whereDate('timestamp', $today)
            ->count();

        $todayAlertTypes = Alert::where('driver_id', $driver)
            ->whereDate('timestamp', $today)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type');

        // ── Current active trip ───────────────────────────────────────────────
        $currentTrip = Trip::with('device')
            ->where('driver_id', $driver)
            ->where('status', 'active')
            ->latest('start_time')
            ->first();

        $currentTripData = null;
        if ($currentTrip) {
            $durationMinutes = now()->diffInMinutes($currentTrip->start_time);
            $currentTripData = [
                'trip_id'  => $currentTrip->trip_id,
                'duration' => $durationMinutes,             // minutes so far
                'distance' => $currentTrip->distance ?? 0, // km
                'device'   => $currentTrip->device ? [
                    'device_id'   => $currentTrip->device->device_id,
                    'device_name' => $currentTrip->device->device_name,
                ] : null,
            ];
        }

        // ── Recent alerts (last 5) ────────────────────────────────────────────
        $recentAlerts = Alert::where('driver_id', $driver)
            ->orderBy('timestamp', 'desc')
            ->limit(5)
            ->get(['alert_id', 'type', 'snapshot_url', 'latitude', 'longitude', 'timestamp']);

        // ── Device status ─────────────────────────────────────────────────────
        $device        = $driverModel->activeDevices->first();
        $deviceStatus  = null;
        if ($device) {
            $deviceStatus = [
                'device_id'   => $device->device_id,
                'device_name' => $device->device_name,
                'is_online'   => $device->status === 'ออนไลน์',
                'status'      => $device->status,
                'last_active' => $device->updated_at,
            ];
        }

        // ── All-time stats for profile card ──────────────────────────────────
        $totalTrips  = Trip::where('driver_id', $driver)->count();
        $totalAlerts = Alert::where('driver_id', $driver)->count();

        // Simple safe-score: trips without any alert / total trips × 100
        $tripsWithAlert = Trip::where('driver_id', $driver)
            ->whereHas('alerts')
            ->count();
        $safeScore = $totalTrips > 0
            ? round((($totalTrips - $tripsWithAlert) / $totalTrips) * 100, 1)
            : 100.0;

        return response()->json([
            'success' => true,
            'data'    => [
                'today_trips'        => $todayTrips,
                'today_alerts'       => $todayAlerts,
                'today_alert_types'  => $todayAlertTypes,
                'current_trip'       => $currentTripData,
                'recent_alerts'      => $recentAlerts,
                'device_status'      => $deviceStatus,
                'stats'              => [
                    'total_trips'  => $totalTrips,
                    'total_alerts' => $totalAlerts,
                    'safe_score'   => $safeScore,
                ],
            ],
        ]);
    }

    /**
     * GET /api/drivers/{driver}/trips/{trip}/summary
     *
     * Detailed summary for HistoryDetailScreen:
     *   - duration, distance, average speed
     *   - alert counts by type
     *   - route overview (first & last location)
     */
    public function tripSummary($driver, $trip)
    {
        $tripModel = Trip::with(['locations', 'alerts', 'device'])
            ->where('driver_id', $driver)
            ->where('trip_id', $trip)
            ->firstOrFail();

        // Duration
        $durationMinutes = null;
        if ($tripModel->start_time && $tripModel->end_time) {
            $durationMinutes = (int) \Carbon\Carbon::parse($tripModel->start_time)
                ->diffInMinutes($tripModel->end_time);
        }

        // Average speed from locations
        $locations = $tripModel->locations;
        $avgSpeed  = $locations->whereNotNull('speed')->avg('speed');

        // Alert breakdown
        $alertsByType = $tripModel->alerts
            ->groupBy('type')
            ->map(fn ($group) => $group->count());

        // Route overview
        $firstLoc = $locations->sortBy('recorded_at')->first();
        $lastLoc  = $locations->sortByDesc('recorded_at')->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'trip_id'        => $tripModel->trip_id,
                'status'         => $tripModel->status,
                'start_time'     => $tripModel->start_time,
                'end_time'       => $tripModel->end_time,
                'duration'       => $tripModel->duration ?? ($durationMinutes ? "{$durationMinutes} นาที" : null),
                'distance'       => $tripModel->distance,         // km
                'avg_speed'      => $avgSpeed ? round($avgSpeed, 1) : null, // km/h
                'total_alerts'   => $tripModel->alerts->count(),
                'alerts_by_type' => $alertsByType,
                'device'         => $tripModel->device ? [
                    'device_id'   => $tripModel->device->device_id,
                    'device_name' => $tripModel->device->device_name,
                ] : null,
                'route' => [
                    'start' => $firstLoc ? [
                        'latitude'    => $firstLoc->latitude,
                        'longitude'   => $firstLoc->longitude,
                        'recorded_at' => $firstLoc->recorded_at,
                    ] : null,
                    'end' => $lastLoc ? [
                        'latitude'    => $lastLoc->latitude,
                        'longitude'   => $lastLoc->longitude,
                        'recorded_at' => $lastLoc->recorded_at,
                    ] : null,
                    'waypoints_count' => $locations->count(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/drivers/{id}/alerts/summary
     *
     * Alert trends for RiskSummaryScreen:
     *   - daily counts for the last 7 days
     *   - breakdown by type (all-time and last 7 days)
     *   - top alert type
     */
    public function alertSummary($driver)
    {
        Driver::findOrFail($driver);

        $days = 7;
        $from = now()->subDays($days - 1)->startOfDay();

        // Daily counts for the last N days
        $daily = Alert::where('driver_id', $driver)
            ->where('timestamp', '>=', $from)
            ->select(
                DB::raw('DATE(timestamp) as date'),
                DB::raw('count(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(fn ($row) => $row->count);

        // Fill missing days with 0 so the chart always has 7 data points
        $filledDaily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date              = now()->subDays($i)->toDateString();
            $filledDaily[$date] = $daily[$date] ?? 0;
        }

        // Type breakdown — last 7 days
        $typeBreakdown7Days = Alert::where('driver_id', $driver)
            ->where('timestamp', '>=', $from)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->orderByDesc('count')
            ->pluck('count', 'type');

        // Type breakdown — all time
        $typeBreakdownAll = Alert::where('driver_id', $driver)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->orderByDesc('count')
            ->pluck('count', 'type');

        $topAlertType = $typeBreakdownAll->keys()->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'daily_counts_7d'    => $filledDaily,
                'type_breakdown_7d'  => $typeBreakdown7Days,
                'type_breakdown_all' => $typeBreakdownAll,
                'top_alert_type'     => $topAlertType,
                'total_7d'           => array_sum($filledDaily),
                'total_all'          => $typeBreakdownAll->sum(),
            ],
        ]);
    }
}
