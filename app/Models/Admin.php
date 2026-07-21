<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table      = 'admins';
    protected $primaryKey = 'admin_id';
    public $incrementing  = false;
    // เปลี่ยนจาก int เป็น char - ระบุ primary key เป็น string type
    protected $keyType = 'string';
    public $timestamps    = false;

    protected $fillable = [
        'admin_id',  // เพิ่มเพราะ primary key เป็น string/char แล้ว
        'username',
        'email',
        'password',
        'full_name',
        'role_label',
        'avatar_url'
    ];

    protected $hidden = ['password', 'password_note'];
}
