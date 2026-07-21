<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceMedia extends Model
{
    protected $primaryKey = 'media_id';
    public $incrementing = false;
    // เปลี่ยนจาก bigint เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';

    protected $fillable = [
        'media_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'device_id',
        'type',
        'file_name',
        'file_path',
        'url',
        'file_size',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
