<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $primaryKey = 'device_id';
    public $incrementing = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';

    protected $fillable = [
        'device_id',      // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'serial_number',
        'device_name',
        'device_type',    // เพิ่ม: ใช้ตอน store() ใน DeviceController
        'status',
        'last_active',    // เพิ่ม: ใช้ตอน heartbeat() / markStaleDevicesOffline()
        'last_heartbeat_at',
        'is_active',
        'ip_address',
        'ip_updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'ip_updated_at' => 'datetime',
    ];

    public function drivers()
    {
        return $this->belongsToMany(
            Driver::class,
            'driver_devices',
            'device_id',
            'driver_id',
            'device_id',
            'driver_id'
        )->withPivot(['assigned_at', 'unassigned_at', 'is_active']);
    }

    public function driverDevices()
    {
        return $this->hasMany(DriverDevice::class, 'device_id', 'device_id');
    }

    public function setting()
    {
        return $this->hasOne(DeviceSetting::class, 'device_id', 'device_id');
    }

    public function media()
    {
        return $this->hasMany(DeviceMedia::class, 'device_id', 'device_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'device_id', 'device_id');
    }
}
