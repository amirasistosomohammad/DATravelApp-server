<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class IctAdmin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string     ASA               
     */
    protected $table = 'ict_admins';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'name',
        'position',
        'department',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Create a token with 12-hour expiration
     *
     * @param string $name
     * @return \Laravel\Sanctum\NewAccessToken
     */
    public function createToken(string $name = 'auth-token', array $abilities = ['*'])
    {
        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken = \Illuminate\Support\Str::random(40)),
            'abilities' => $abilities,
            'expires_at' => now()->addHours(12),
        ]);

        return new \Laravel\Sanctum\NewAccessToken($token, $token->id.'|'.$plainTextToken);
    }
}

