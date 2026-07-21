<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Http\Controllers\Api\DeviceController;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // เรียกฟังก์ชันตัดสัญญาณอุปกรณ์ที่ขาดการติดต่อผ่าน DeviceController ทุกๆ 1 นาที
        $schedule->call(fn () => DeviceController::markStaleDevicesOffline())->everyMinute();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
