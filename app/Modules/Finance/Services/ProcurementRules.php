<?php

namespace App\Modules\Finance\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\ProcurementRuleSet;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowException;
use Illuminate\Support\Facades\DB;

/**
 * Which procurement method applies to a value (PROC-002). The bands are
 * data entered and published by Finance, citing their authority (GFR rule,
 * DoE O.M., institute order); nothing here knows any threshold.
 */
class ProcurementRules
{
    public const METHODS = [
        'direct' => 'Purchase without quotation',
        'quotations' => 'Purchase committee / limited quotations',
        'gem' => 'GeM',
        'limited_tender' => 'Limited tender',
        'open_tender' => 'Open tender',
    ];

    public function __construct(private AuditLogger $audit) {}

    /** @return array{rule_set_id: int, rule_version: int, band: int, label: string, method: string, quotations: int, committee: bool, value: float} */
    public function decide(float $value): array
    {
        $set = ProcurementRuleSet::current() ?? throw new WorkflowException('No procurement rule set is published. Finance must publish one before indents can be raised.', 422, ['items' => 'No procurement rules in force.']);
        foreach (array_values($set->bands) as $i => $band) {
            if ($band['up_to'] === null || $value <= (float) $band['up_to']) {
                return [
                    'rule_set_id' => $set->id, 'rule_version' => $set->version, 'band' => $i, 'label' => (string) $band['label'],
                    'method' => (string) $band['method'], 'quotations' => (int) ($band['quotations'] ?? 0), 'committee' => (bool) ($band['committee'] ?? false),
                    'value' => $value, 'authority' => $set->authority,
                ];
            }
        }
        throw new WorkflowException('The published rule set has no band for this value. The last band must be open-ended.', 422);
    }

    /** @return list<string> */
    public function problems(array $bands): array
    {
        $problems = [];
        if ($bands === []) {
            return ['Add at least one band.'];
        }
        $previous = 0.0;
        foreach (array_values($bands) as $i => $b) {
            $n = $i + 1;
            if (! array_key_exists($b['method'] ?? '', self::METHODS)) {
                $problems[] = "Band {$n}: choose a method.";
            }
            if (trim((string) ($b['label'] ?? '')) === '') {
                $problems[] = "Band {$n}: give it a label.";
            }
            $last = $i === count($bands) - 1;
            if ($b['up_to'] === null && ! $last) {
                $problems[] = "Band {$n}: only the last band can be open-ended.";
            }
            if ($b['up_to'] !== null) {
                if ($last) {
                    $problems[] = 'The last band must be open-ended (no upper limit).';
                }
                if ((float) $b['up_to'] <= $previous) {
                    $problems[] = "Band {$n}: limits must increase.";
                }
                $previous = (float) $b['up_to'];
            }
            if ((int) ($b['quotations'] ?? 0) < 0) {
                $problems[] = "Band {$n}: quotations cannot be negative.";
            }
        }

        return $problems;
    }

    public function saveDraft(?ProcurementRuleSet $set, array $data, User $by): ProcurementRuleSet
    {
        if ($set && $set->status !== 'draft') {
            throw new WorkflowException('Published rule sets cannot be edited. Draft a new version.', 409);
        }
        $set ??= (new ProcurementRuleSet)->forceFill(['version' => (int) ProcurementRuleSet::query()->max('version') + 1, 'status' => 'draft', 'created_by' => $by->id]);
        $set->forceFill(['title' => $data['title'], 'authority' => $data['authority'] ?? null, 'bands' => array_values($data['bands']), 'effective_from' => $data['effective_from'] ?? null])->save();
        $this->audit->record('procurement.rules.saved', $set, ['version' => $set->version], actor: $by);

        return $set;
    }

    public function publish(ProcurementRuleSet $set, User $by): ProcurementRuleSet
    {
        return DB::transaction(function () use ($set, $by) {
            $set = ProcurementRuleSet::query()->lockForUpdate()->findOrFail($set->id);
            if ($set->status !== 'draft') {
                throw new WorkflowException('Already published.', 409);
            }
            if ($p = $this->problems($set->bands)) {
                throw new WorkflowException('Fix before publishing: '.$p[0], 422, ['bands' => $p[0]]);
            }
            ProcurementRuleSet::query()->where('status', 'published')->update(['status' => 'retired', 'updated_at' => now()]);
            ProcurementRuleSet::query()->whereKey($set->id)->update(['status' => 'published', 'published_at' => now(), 'published_by' => $by->id, 'updated_at' => now()]);
            $this->audit->record('procurement.rules.published', $set, ['version' => $set->version, 'bands' => $set->bands, 'authority' => $set->authority], actor: $by);

            return $set->refresh();
        });
    }
}
