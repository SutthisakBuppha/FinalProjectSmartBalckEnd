<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $primaryKey = 'trip_id';
    public $incrementing = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';

    protected $fillable = [
        'trip_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'driver_id',
        'device_id',
        'start_time',
        'end_time',
        'duration',
        'distance',
        'status',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'distance'   => 'decimal:2',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'driver_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function alerts()
    {
        return $this->hasMany(Alert::class, 'trip_id', 'trip_id');
    }

    public function locations()
    {
        return $this->hasMany(TripLocation::class, 'trip_id', 'trip_id');
    }
}
