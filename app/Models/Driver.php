<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Driver extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'drivers';
    protected $primaryKey = 'driver_id';
    // public $incrementing = true;
    public $incrementing = false ;
    protected $keyType = 'string';

    protected $fillable = [
        'driver_id',
        'username',
        'password',
        'name',
        'email',
        'avatar_url',
        'status',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function trips()
    {
        return $this->hasMany(Trip::class, 'driver_id');
    }

    public function alerts()
    {
        return $this->hasMany(Alert::class, 'driver_id');
    }

    public function devices()
    {
        return $this->belongsToMany(Device::class, 'driver_devices', 'driver_id', 'device_id')
            ->withPivot(['assigned_at', 'unassigned_at', 'is_active']);
    }

    public function activeDevices()
    {
        return $this->belongsToMany(Device::class, 'driver_devices', 'driver_id', 'device_id')
            ->withPivot(['assigned_at', 'unassigned_at', 'is_active'])
            ->wherePivot('is_active', true)
            ->wherePivotNull('unassigned_at');
    }
}
