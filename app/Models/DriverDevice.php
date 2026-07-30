<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverDevice extends Model
{
    use HasFactory;

    protected $primaryKey = 'driver_device_id';
    public $incrementing = false;   // ← เพิ่ม: ไม่งั้น insert จะพังเพราะ PK เป็น char ไม่ใช่ int
    protected $keyType = 'string';  // ← เพิ่ม: บอก Eloquent ว่า PK เป็น string
    public $timestamps = false;     // สำคัญ ตารางไม่มี created_at/updated_at

    protected $fillable = [
        'driver_device_id',  // ← เพิ่ม: เพราะ PK เป็น string/char แล้ว ต้อง gen เองด้วย Str::random()
        'driver_id',
        'device_id',
        'assigned_at',
        'unassigned_at',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'driver_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
