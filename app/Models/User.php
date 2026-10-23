<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return int[] */
    public function roleIds(): array
    {
        return $this->perRequest('role_ids', fn () => $this->roles()->pluck('roles.id')->all());
    }

    public function isAdmin(): bool
    {
        return $this->perRequest('is_admin', fn () => $this->roles()->where('code', Role::ADMIN)->exists());
    }

    /**
     * Worked out once per request: access checks ask many times. Kept on the request, not
     * the model, as one user object can outlive a request (e.g. Sanctum::actingAs in tests).
     */
    private function perRequest(string $key, callable $value): mixed
    {
        $cache = request()->attributes;
        $key = "user.{$this->id}.{$key}";
        if (! $cache->has($key)) {
            $cache->set($key, $value());
        }

        return $cache->get($key);
    }

    /** What this user has been told about. */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

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
        ];
    }
}
