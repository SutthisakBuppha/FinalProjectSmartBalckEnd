<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    protected $primaryKey = 'alert_id';
    public $incrementing = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';
    public $timestamps = false;  // DB ไม่มี created_at/updated_at, มีแค่ timestamp

    protected $fillable = [
        'alert_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'trip_id',
        'driver_id',
        'device_id',
        'type',
        'snapshot_url',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude'  => 'decimal:8',
        'longitude' => 'decimal:8',
        'timestamp' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'driver_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
