<?php

namespace App\Modules\ComputerCentre\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\ComputerCentre\Models\ItAsset;
use App\Modules\ComputerCentre\Models\ItAssetMovement;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IT asset register (ITSM-005). Every change of custody or condition is a
 * movement row and an audit event; state changes are row-locked so an asset
 * cannot be issued to two people at once.
 */
class AssetService
{
    /** action => [allowed from statuses, resulting status] */
    private const MOVES = [
        'issue' => [['in_stock'], 'issued'],
        'return' => [['issued'], 'in_stock'],
        'transfer' => [['issued'], 'issued'],
        'repair' => [['in_stock', 'issued'], 'repair'],
        'repaired' => [['repair'], 'in_stock'],
        'dispose' => [['in_stock', 'repair'], 'disposed'],
    ];

    public function __construct(private AuditLogger $audit) {}

    public function register(array $data, User $by): ItAsset
    {
        return DB::transaction(function () use ($data, $by) {
            $asset = ItAsset::create($data);
            $asset->forceFill(['status' => 'in_stock'])->save();
            $this->movement($asset, 'register', null, null, $by, null, null);
            $this->audit->record('cc.asset.registered', $asset, $asset->only('asset_number', 'category', 'serial_number'), actor: $by);

            return $asset;
        });
    }

    public function move(ItAsset $asset, string $action, ?User $by, ?User $to = null, ?string $remarks = null, ?int $instanceId = null): ItAsset
    {
        [$from, $result] = self::MOVES[$action] ?? throw ValidationException::withMessages(['action' => 'Unknown action.']);

        return DB::transaction(function () use ($asset, $action, $by, $to, $remarks, $instanceId, $from, $result) {
            $asset = ItAsset::query()->lockForUpdate()->findOrFail($asset->id);

            // A replayed workflow step that already issued this asset to this person is fine.
            if ($action === 'issue' && $asset->status === 'issued' && $to && $asset->custodian_id === $to->id) {
                return $asset;
            }
            if (! in_array($asset->status, $from, true)) {
                throw ValidationException::withMessages(['action' => "Asset {$asset->asset_number} is ".str_replace('_', ' ', $asset->status).'; it cannot be '.($action === 'repaired' ? 'marked repaired' : "{$action}d").' now.']);
            }
            if (in_array($action, ['issue', 'transfer'], true) && (! $to || ! $to->canSignIn())) {
                throw ValidationException::withMessages(['to' => 'Choose an active person to receive the asset.']);
            }
            if ($action === 'transfer' && $to->id === $asset->custodian_id) {
                throw ValidationException::withMessages(['to' => 'The asset is already with this person.']);
            }

            $previous = $asset->custodian_id;
            $asset->forceFill([
                'status' => $result,
                'custodian_id' => match ($action) {
                    'issue', 'transfer' => $to->id,
                    'return', 'dispose' => null,
                    default => $asset->custodian_id,
                },
            ])->save();
            $this->movement($asset, $action, $previous, $asset->custodian_id, $by, $remarks, $instanceId);
            $this->audit->record("cc.asset.{$action}", $asset, ['from' => $previous, 'to' => $asset->custodian_id, 'remarks' => $remarks], actor: $by);

            return $asset;
        });
    }

    private function movement(ItAsset $asset, string $action, ?int $from, ?int $to, ?User $by, ?string $remarks, ?int $instanceId): void
    {
        (new ItAssetMovement)->forceFill([
            'it_asset_id' => $asset->id, 'action' => $action, 'from_user_id' => $from, 'to_user_id' => $to, 'by_user_id' => $by?->id,
            'workflow_instance_id' => $instanceId, 'remarks' => $remarks, 'created_at' => now(),
        ])->save();
    }
}
