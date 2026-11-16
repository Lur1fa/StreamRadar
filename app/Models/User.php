<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_USER];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'oidc_issuer',
        'oidc_subject',
        'last_login_at',
        'disabled_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'oidc_subject',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function hasLocalPassword(): bool
    {
        return ! empty($this->password);
    }

    public function isSsoLinked(): bool
    {
        return ! empty($this->oidc_subject);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_ADMIN);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    public static function findByEmail(string $email): ?self
    {
        return static::whereRaw('lower(email) = ?', [strtolower(trim($email))])->first();
    }

    /** Shape shared with the frontend; never includes credentials or SSO identifiers. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_admin' => $this->isAdmin(),
            'has_password' => $this->hasLocalPassword(),
            'sso' => $this->isSsoLinked(),
            'disabled' => $this->isDisabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
