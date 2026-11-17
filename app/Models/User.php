<?php

namespace App\Models;

use App\Services\AccountDataService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    protected static function booted(): void
    {
        // An installation upgraded from the time all data was shared, with no
        // account yet, has data without an owner. The first administrator
        // takes it over. A no-op in every other case.
        static::saved(function (User $user) {
            if (! $user->isAdmin() || ! ($user->wasRecentlyCreated || $user->wasChanged('role'))) {
                return;
            }

            try {
                $accounts = app(AccountDataService::class);
                if ($accounts->hasOrphans()) {
                    $accounts->adoptOrphans($user);
                }
            } catch (Throwable $e) {
                Log::warning('Could not assign existing data to the new administrator.', ['exception' => $e::class]);
            }
        });
    }

    // ── Data owned by the account ────────────────────────────────────────

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'owner_id');
    }

    public function tagFilters(): HasMany
    {
        return $this->hasMany(TagFilter::class, 'owner_id');
    }

    public function trackedChannels(): HasMany
    {
        return $this->hasMany(TrackedChannel::class, 'owner_id');
    }

    public function blacklistRules(): HasMany
    {
        return $this->hasMany(BlacklistRule::class, 'owner_id');
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class, 'owner_id');
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(HistoryEvent::class, 'owner_id');
    }

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class, 'owner_id');
    }

    public function notificationDestinations(): HasMany
    {
        return $this->hasMany(NotificationDestination::class, 'owner_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class);
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
