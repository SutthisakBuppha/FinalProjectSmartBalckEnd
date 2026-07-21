<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripLocation extends Model
{
    protected $primaryKey = 'location_id';
    public $incrementing = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'location_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'trip_id',
        'latitude',
        'longitude',
        'speed',
        'recorded_at',
    ];

    protected $casts = [
        'latitude'    => 'decimal:8',
        'longitude'   => 'decimal:8',
        'speed'       => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
