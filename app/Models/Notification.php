<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $table = 'notifications';
    protected $primaryKey = 'noti_id';  // DB ใช้ noti_id ไม่ใช่ notification_id
    public $incrementing = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';
    const UPDATED_AT = null;

    protected $fillable = [
        'noti_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'driver_id',
        'alert_id',
        'message',
        'is_read',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'created_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'driver_id');
    }

    public function alert()
    {
        return $this->belongsTo(Alert::class, 'alert_id', 'alert_id');
    }
}
