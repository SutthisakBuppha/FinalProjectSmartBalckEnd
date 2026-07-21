<?php
/**
 * วางไฟล์นี้ที่ app/Console/Commands/MarkDevicesOffline.php
 */

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class MarkDevicesOffline extends Command
{
    protected $signature = 'devices:mark-offline';

    protected $description = 'เปลี่ยนสถานะอุปกรณ์เป็น "ออฟไลน์" ถ้าไม่มี heartbeat เข้ามานานเกินกำหนด (เช่น ถอดปลั๊กไฟ)';

    /**
     * ต้องมากกว่ารอบเวลาที่บอร์ดยิง heartbeat มาเสมอ
     * ถ้าบอร์ดยิงทุก 15-30 วิ ตั้ง timeout ไว้สัก 60-90 วิ กำลังดี
     * กันเคสสัญญาณ Wi-Fi สะดุดชั่วครู่ไม่ให้โดนตีเป็นออฟไลน์เร็วเกินไป
     */
    private const TIMEOUT_SECONDS = 60;

    public function handle(): int
    {
        $count = Device::where('status', 'ออนไลน์')
            ->where(function ($query) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subSeconds(self::TIMEOUT_SECONDS));
            })
            ->update([
                'status'      => 'ออฟไลน์',
                'last_active' => now()->format('d/m/Y H:i'),
            ]);

        $this->info("เปลี่ยนสถานะอุปกรณ์เป็นออฟไลน์แล้ว: {$count} เครื่อง");

        return self::SUCCESS;
    }
}
