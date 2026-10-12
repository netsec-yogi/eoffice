<?php

namespace App\Modules\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/*
 | Directory-derived fields are written by UserProvisioner, organisational
 | fields by administrators. Activation, auth source and directory state are
 | never mass-assignable: set them with forceFill in the owning service.
 */
#[Fillable(['name', 'email', 'phone', 'employee_id', 'category', 'department_id', 'designation_id', 'reports_to_id'])]
#[Hidden(['password', 'remember_token', 'ldap_dn'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasFactory, HasRoles;

    public const CATEGORIES = ['faculty', 'staff', 'student', 'external', 'vendor'];

    protected string $guard_name = 'web';

    /** Mirrors the column defaults, so new models behave before a refresh. */
    protected $attributes = [
        'category' => 'staff',
        'auth_source' => 'ldap',
        'is_active' => true,
        'directory_disabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'directory_disabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    public function authorityAssignments(): HasMany
    {
        return $this->hasMany(AuthorityAssignment::class);
    }

    public function committeeMemberships(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }

    /** Whether this account may sign in at all, independent of credentials. */
    public function canSignIn(): bool
    {
        return $this->is_active && ! $this->directory_disabled;
    }

    public function isLocal(): bool
    {
        return $this->auth_source === 'local';
    }

    /** Vendors and other outside parties: confined to their portal (ConfineExternalUsers). */
    public function isExternal(): bool
    {
        return in_array($this->category, ['vendor', 'external'], true);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->where('directory_disabled', false);
    }

    /** Label used in audit trails and timelines: "Name (Designation)". */
    public function displayLabel(): string
    {
        $designation = $this->relationLoaded('designation') ? $this->designation?->name : null;

        return $designation ? "{$this->name} ({$designation})" : $this->name;
    }
}
