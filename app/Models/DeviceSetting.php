<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceSetting extends Model
{
    use HasFactory;

    protected $primaryKey = 'setting_id';
    public $incrementing = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';
    public $timestamps    = false;

    protected $fillable = [
        'setting_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'device_id',
        'volume_level',
        'sound_enabled',
        'active_tone',
    ];

    protected $casts = [
        'sound_enabled' => 'boolean',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
