<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'role_id',
        'name',
        'phone',
        'email',
        'email_verified_at',
        'password',
        'status',
        'last_login_at',
    ];

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
            'status' => 'string',
            'last_login_at' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isBusinessOwner(): bool
    {
        return $this->hasRole('business_owner');
    }

    public function isBusinessAdmin(): bool
    {
        return $this->hasRole('business_admin');
    }

    public function isDriver(): bool
    {
        return $this->hasRole('driver');
    }

    public function isCustomer(): bool
    {
        return $this->hasRole('customer');
    }

    public function belongsToBusiness(int|string|null $businessId): bool
    {
        return $businessId !== null
            && $this->business_id !== null
            && (string) $this->business_id === (string) $businessId;
    }

    public function canAccessBusiness(int|string|null $businessId): bool
    {
        return $this->isSuperAdmin() || $this->belongsToBusiness($businessId);
    }

    public function hasRole(string $roleName): bool
    {
        if ($this->relationLoaded('role')) {
            return $this->role?->name === $roleName;
        }

        return $this->role()->where('name', $roleName)->exists();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessBranch::class, 'branch_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function createdDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'created_by');
    }

    public function assignedDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'assigned_driver_id');
    }

    public function deliveryStatusLogs(): HasMany
    {
        return $this->hasMany(DeliveryStatusLog::class, 'changed_by');
    }

    public function trackingSessions(): HasMany
    {
        return $this->hasMany(DeliveryTrackingSession::class, 'driver_id');
    }

    public function trackingLocations(): HasMany
    {
        return $this->hasMany(DeliveryTrackingLocation::class, 'driver_id');
    }

    public function deliveryProofs(): HasMany
    {
        return $this->hasMany(DeliveryProof::class, 'driver_id');
    }

    public function deliveryFailures(): HasMany
    {
        return $this->hasMany(DeliveryFailure::class, 'driver_id');
    }

    public function deliveryPayments(): HasMany
    {
        return $this->hasMany(DeliveryPayment::class, 'driver_id');
    }

    public function cashReconciliations(): HasMany
    {
        return $this->hasMany(CashReconciliation::class, 'driver_id');
    }

    public function reconciledCashReconciliations(): HasMany
    {
        return $this->hasMany(CashReconciliation::class, 'reconciled_by');
    }
}
