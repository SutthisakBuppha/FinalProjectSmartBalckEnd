<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverDevice extends Model
{
    use HasFactory;

    protected $primaryKey = 'driver_device_id';
    public $timestamps = false;   // ← สำคัญ ตารางไม่มี created_at/updated_at

    protected $fillable = [
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