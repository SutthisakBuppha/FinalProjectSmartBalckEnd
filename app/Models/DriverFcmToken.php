<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverFcmToken extends Model
{
    use HasFactory;

    protected $table = 'driver_fcm_tokens';
    protected $primaryKey = 'fcm_token_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'fcm_token_id',
        'driver_id',
        'token',
        'platform',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'driver_id');
    }
}
