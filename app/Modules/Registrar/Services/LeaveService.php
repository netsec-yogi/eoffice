<?php

namespace App\Modules\Registrar\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Calendar\Services\BusinessTime;
use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveLedgerEntry;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Leave arithmetic and the leave ledger (LEAVE-001). */
class LeaveService
{
    public function __construct(private BusinessTime $time, private AuditLogger $audit) {}

    /** Days of leave between two dates for this type (holidays excluded unless the type counts them). */
    public function days(LeaveType $type, string $from, string $to, bool $halfDay = false): float
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $days = 0;
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            if ($type->counts_holidays || $this->time->isWorkingDay($d)) {
                $days++;
            }
        }

        return $halfDay && $start->equalTo($end) && $days === 1 ? 0.5 : (float) $days;
    }

    public function ledgerBalance(User $user, LeaveType $type): float
    {
        return (float) LeaveLedgerEntry::query()->where(['user_id' => $user->id, 'leave_type_id' => $type->id])->sum('days');
    }

    /** Days held by applications not yet decided. */
    public function pending(User $user, LeaveType $type, ?int $exceptInstance = null): float
    {
        return (float) LeaveRecord::query()->where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'status' => 'pending'])
            ->when($exceptInstance, fn ($q) => $q->where('workflow_instance_id', '!=', $exceptInstance))->sum('days');
    }

    public function available(User $user, LeaveType $type, ?int $exceptInstance = null): float
    {
        return $this->ledgerBalance($user, $type) - $this->pending($user, $type, $exceptInstance);
    }

    /** Leave that overlaps the period, excluding the given request. */
    public function overlapping(User $user, string $from, string $to, ?int $exceptInstance = null): ?LeaveRecord
    {
        return LeaveRecord::query()->with('type:id,name')->where('user_id', $user->id)->whereIn('status', ['pending', 'approved', 'curtailed'])
            ->where('from_date', '<=', $to)->where('to_date', '>=', $from)
            ->when($exceptInstance, fn ($q) => $q->where('workflow_instance_id', '!=', $exceptInstance))->first();
    }

    /** Post a ledger entry once (repeat calls for the same record/kind/request are ignored). */
    public function post(User $user, LeaveType $type, string $kind, float $days, string $reason, ?User $by = null, ?LeaveRecord $record = null, ?int $instanceId = null): ?LeaveLedgerEntry
    {
        try {
            return DB::transaction(function () use ($user, $type, $kind, $days, $reason, $by, $record, $instanceId) {
                $entry = new LeaveLedgerEntry;
                $entry->forceFill([
                    'user_id' => $user->id, 'leave_type_id' => $type->id, 'kind' => $kind, 'days' => $days, 'reason' => mb_substr($reason, 0, 255),
                    'leave_record_id' => $record?->id, 'workflow_instance_id' => $instanceId, 'created_by' => $by?->id, 'created_at' => now(),
                ])->save();
                $this->audit->record("leave.ledger.{$kind}", $user, ['type' => $type->code, 'days' => $days, 'reason' => $reason], actor: $by);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // already posted
        }
    }
}
