<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) เพิ่มคอลัมน์เก็บเวลาที่ได้รับ heartbeat ล่าสุดจากบอร์ด
        //    ใช้ตัดสินว่า device ไหน "เงียบ" ไปนานเกินกำหนดแล้ว ควรเปลี่ยนเป็นออฟไลน์
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_active');
        });

        // 2) เพิ่ม 'audio' เข้าไปใน ENUM ของ device_media.type
        //    ต้องแก้ตรงนี้ด้วย เพราะ column เป็น ENUM จริงในฐานข้อมูล
        //    (แก้แค่ validation ฝั่ง Laravel ไม่พอ)
        DB::statement("ALTER TABLE device_media MODIFY COLUMN type ENUM('image','video','audio') NOT NULL");
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });

        DB::statement("ALTER TABLE device_media MODIFY COLUMN type ENUM('image','video') NOT NULL");
    }
};
