<?php

namespace App\Modules\ComputerCentre\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Records granted access; recording the same grant twice is a no-op. */
class AccountLedger
{
    public function __construct(private AuditLogger $audit) {}

    public function record(User $user, string $kind, string $identifier, array $details, ?int $instanceId, string $by = 'automation', ?string $expiresOn = null): ItAccount
    {
        $existing = ItAccount::query()->where(['user_id' => $user->id, 'kind' => $kind, 'identifier' => $identifier])->first();
        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->forceFill(['status' => 'active', 'deprovisioned_at' => null, 'details' => $details, 'expires_on' => $expiresOn, 'workflow_instance_id' => $instanceId])->save();
                $this->audit->record('cc.account.reactivated', $existing, ['kind' => $kind], actor: null);
            }

            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $kind, $identifier, $details, $instanceId, $by, $expiresOn) {
                $account = new ItAccount;
                $account->forceFill([
                    'user_id' => $user->id, 'kind' => $kind, 'identifier' => $identifier, 'status' => 'active', 'details' => $details,
                    'provisioned_by' => $by, 'workflow_instance_id' => $instanceId, 'expires_on' => $expiresOn, 'provisioned_at' => now(),
                ])->save();
                $this->audit->record('cc.account.provisioned', $account, ['kind' => $kind, 'identifier' => $identifier, 'by' => $by, 'user_id' => $user->id], actor: null);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            return ItAccount::query()->where(['user_id' => $user->id, 'kind' => $kind, 'identifier' => $identifier])->firstOrFail();
        }
    }

    public function close(ItAccount $account, string $status, ?int $instanceId): void
    {
        if ($account->status === $status) {
            return;
        }
        $account->forceFill(['status' => $status, 'deprovisioned_at' => $status === 'deprovisioned' ? now() : $account->deprovisioned_at])->save();
        $this->audit->record("cc.account.{$status}", $account, ['kind' => $account->kind, 'instance' => $instanceId], actor: null);
    }
}
