<?php

namespace App\Modules\ComputerCentre\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\ComputerCentre\Models\LicenceAllocation;
use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Software licence seats (ITSM-006). Seat counting happens under a row lock. */
class LicenceService
{
    public function __construct(private AuditLogger $audit) {}

    public function available(SoftwareTitle $title): ?int
    {
        return $title->seats === null ? null : max(0, $title->seats - $title->activeAllocations()->count());
    }

    public function allocate(SoftwareTitle $title, User $user, ?User $by = null, ?int $instanceId = null): LicenceAllocation
    {
        return DB::transaction(function () use ($title, $user, $by, $instanceId) {
            $title = SoftwareTitle::query()->lockForUpdate()->findOrFail($title->id);
            if ($existing = $title->activeAllocations()->where('user_id', $user->id)->first()) {
                return $existing; // already has a seat
            }
            if (! $title->is_active || ($title->expires_on && $title->expires_on->isPast())) {
                throw ValidationException::withMessages(['software' => "{$title->name}: the licence is not active."]);
            }
            if ($title->seats !== null && $title->activeAllocations()->count() >= $title->seats) {
                throw ValidationException::withMessages(['software' => "{$title->name}: all {$title->seats} seats are in use."]);
            }
            $allocation = new LicenceAllocation;
            $allocation->forceFill(['software_title_id' => $title->id, 'user_id' => $user->id, 'workflow_instance_id' => $instanceId, 'allocated_at' => now()])->save();
            $this->audit->record('cc.licence.allocated', $allocation, ['title' => $title->code, 'user_id' => $user->id], actor: $by);

            return $allocation;
        });
    }

    public function revoke(LicenceAllocation $allocation, ?User $by, string $reason): void
    {
        if ($allocation->revoked_at) {
            return;
        }
        $allocation->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
        $this->audit->record('cc.licence.revoked', $allocation, ['reason' => $reason], actor: $by);
    }
}
