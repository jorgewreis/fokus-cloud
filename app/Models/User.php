<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'cpf', 'email', 'phone', 'password', 'status', 'email_verified_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'login_attempt_window_started_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
