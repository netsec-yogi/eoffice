<?php

namespace App\Modules\Workflow\Graph;

use App\Modules\Calendar\Services\BusinessTime;
use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Workflow\Automation\ActionRegistry;
use App\Modules\Workflow\Engine\AssigneeResolver;
use App\Modules\Workflow\Engine\CommitteeRule;
use App\Modules\Workflow\Forms\FormDataValidator;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Rules\RuleEvaluator;
use Spatie\Permission\Models\Role;

/**
 * Publication check for a workflow graph (WF-004). Errors block
 * publication; warnings are shown but allowed (e.g. an authority that has
 * no holder today). The goal: a published workflow can never get stuck
 * because of how it was drawn.
 */
class GraphValidator
{
    public function __construct(
        private RuleEvaluator $rules,
        private ActionRegistry $actions,
        private FormDataValidator $forms,
    ) {}

    /**
     * @param  list<array{key:string,type:string,name:string,config:array}>  $nodes
     * @param  list<array{key:string,from_node:string,to_node:string,outcome:string,condition:?array}>  $edges
     * @return array{errors: list<array{node: ?string, message: string}>, warnings: list<array{node: ?string, message: string}>}
     */
    public function check(array $nodes, array $edges, ?array $formSchema, array $settings = []): array
    {
        $errors = [];
        $warnings = [];
        $err = function (?string $node, string $m) use (&$errors) {
            $errors[] = ['node' => $node, 'message' => $m];
        };
        $warn = function (?string $node, string $m) use (&$warnings) {
            $warnings[] = ['node' => $node, 'message' => $m];
        };

        $byKey = [];
        foreach ($nodes as $n) {
            if (isset($byKey[$n['key']])) {
                $err($n['key'], "Two steps use the key '{$n['key']}'.");
            }
            $byKey[$n['key']] = $n;
            if (! in_array($n['type'], WorkflowNode::TYPES, true)) {
                $err($n['key'], "Unknown step type '{$n['type']}'.");
            }
            if (trim((string) ($n['name'] ?? '')) === '') {
                $err($n['key'], 'Every step needs a name.');
            }
        }

        $out = [];
        $in = [];
        foreach ($edges as $e) {
            if (! isset($byKey[$e['from_node']], $byKey[$e['to_node']])) {
                $err(null, 'A connection points to a step that does not exist.');

                continue;
            }
            if ($e['from_node'] === $e['to_node']) {
                $err($e['from_node'], 'A step cannot connect to itself.');
            }
            if (! in_array($e['outcome'], WorkflowTransition::OUTCOMES, true)) {
                $err($e['from_node'], "Unknown connection outcome '{$e['outcome']}'.");
            }
            foreach ($this->rules->problems($e['condition'] ?? null) as $p) {
                $err($e['from_node'], "Condition on a connection from '{$byKey[$e['from_node']]['name']}': {$p}.");
            }
            $out[$e['from_node']][] = $e;
            $in[$e['to_node']][] = $e;
        }

        $starts = array_filter($nodes, fn ($n) => $n['type'] === 'start');
        $ends = array_filter($nodes, fn ($n) => $n['type'] === 'end');
        if (count($starts) !== 1) {
            $err(null, 'The workflow needs exactly one Start step.');
        }
        if ($ends === []) {
            $err(null, 'The workflow needs at least one End step.');
        }

        // Reachability: from start forwards, and every step must be able to finish.
        if (count($starts) === 1) {
            $start = array_values($starts)[0]['key'];
            $reached = $this->walk($start, fn ($k) => array_column($out[$k] ?? [], 'to_node'));
            foreach ($nodes as $n) {
                if (! isset($reached[$n['key']])) {
                    $err($n['key'], "'{$n['name']}' cannot be reached from Start.");
                }
            }
            $canFinish = [];
            foreach ($ends as $end) {
                $canFinish += $this->walk($end['key'], fn ($k) => array_column($in[$k] ?? [], 'from_node'));
            }
            foreach ($nodes as $n) {
                if (isset($reached[$n['key']]) && ! isset($canFinish[$n['key']])) {
                    $err($n['key'], "From '{$n['name']}' the request can never reach an End step.");
                }
            }
        }

        $fieldKeys = $formSchema ? array_column($this->forms->fields($formSchema), 'key') : [];
        // Values computed by the workflow's submission validator count as fields.
        if (($class = config('workflow.validators')[$settings['validator'] ?? ''] ?? null) && class_exists($class)) {
            $fieldKeys = [...$fieldKeys, ...app($class)->provides()];
        }
        $knownField = fn (string $path) => in_array(explode('.', $path)[0], $fieldKeys, true) || preg_match('/^(initiator|vars)\./', $path);

        foreach ($nodes as $n) {
            $k = $n['key'];
            $name = $n['name'];
            $cfg = $n['config'] ?? [];
            $outs = $out[$k] ?? [];
            $outcomes = array_column($outs, 'outcome');
            $has = fn (string $o) => in_array($o, $outcomes, true);

            foreach ($outs as $e) {
                foreach ($this->rules->fields($e['condition'] ?? null) as $path) {
                    if (! $knownField($path)) {
                        $warn($k, "A condition after '{$name}' uses '{$path}', which is not a field of the form.");
                    }
                }
                $allowedOutcomes = match (true) {
                    in_array($n['type'], WorkflowNode::HUMAN, true) => ['approve', 'reject', 'default'],
                    $n['type'] === 'automation' => ['success', 'failure', 'default'],
                    default => ['default'],
                };
                if (! in_array($e['outcome'], $allowedOutcomes, true)) {
                    $err($k, "A '{$n['type']}' step cannot have a '{$e['outcome']}' connection.");
                }
            }

            switch ($n['type']) {
                case 'start':
                    if (count($outs) !== 1) {
                        $err($k, 'Start must have exactly one outgoing connection.');
                    }
                    if (! empty($in[$k])) {
                        $err($k, 'Nothing can lead back into Start.');
                    }
                    break;

                case 'end':
                    if ($outs !== []) {
                        $err($k, 'An End step cannot have outgoing connections.');
                    }
                    if (! in_array($cfg['outcome'] ?? 'approved', ['approved', 'rejected', 'cancelled'], true)) {
                        $err($k, 'End outcome must be approved, rejected or cancelled.');
                    }
                    break;

                case 'approval':
                case 'review':
                case 'verification':
                case 'signature':
                    $this->checkAssignees($cfg['assignees'] ?? [], $k, $name, $err, $warn, $fieldKeys);
                    $actions = $cfg['actions'] ?? ['approve', 'reject', 'return'];
                    if (array_diff($actions, ['approve', 'reject', 'return', 'forward', 'hold']) !== []) {
                        $err($k, 'Unknown action in the allowed actions.');
                    }
                    if (! in_array('approve', $actions, true)) {
                        $err($k, "'{$name}' must allow approve (or the request could never move on).");
                    }
                    if (! $has('approve') && ! $has('default')) {
                        $err($k, "'{$name}' needs a connection for approval.");
                    }
                    if (in_array('reject', $actions, true) && ! $has('reject')) {
                        $err($k, "'{$name}' allows rejection but has no rejection connection.");
                    }
                    if (! in_array($cfg['mode'] ?? 'any', ['any', 'all'], true)) {
                        $err($k, 'Mode must be "any" or "all".');
                    }
                    if ($n['type'] === 'signature' && ! config('signatures.provider')) {
                        $err($k, 'No electronic-signature provider is configured, so signature steps cannot be published yet (see docs/INTEGRATIONS.md).');
                    }
                    $this->checkSla($cfg['sla'] ?? null, $k, $err, $fieldKeys, $warn);
                    break;

                case 'committee':
                    $code = $cfg['committee'] ?? null;
                    if (! $code || ! Committee::query()->where('code', $code)->exists()) {
                        $err($k, "'{$name}' must name an existing committee.");
                    }
                    $rule = $cfg['rule'] ?? null;
                    if (! in_array($rule, CommitteeRule::RULES, true)) {
                        $err($k, "'{$name}' must state how the committee decides (any, all, majority, quorum or weighted).");
                    }
                    if ($rule === 'quorum' && (int) ($cfg['quorum'] ?? 0) < 1) {
                        $err($k, 'A quorum rule needs the number of approvals required.');
                    }
                    if ($rule === 'weighted' && ! (($cfg['threshold'] ?? 0) > 0 && $cfg['threshold'] <= 1)) {
                        $err($k, 'A weighted rule needs a threshold between 0 and 1 (e.g. 0.66).');
                    }
                    if (! $has('approve') && ! $has('default')) {
                        $err($k, "'{$name}' needs a connection for approval.");
                    }
                    if (! $has('reject')) {
                        $err($k, "'{$name}' needs a rejection connection.");
                    }
                    $this->checkSla($cfg['sla'] ?? null, $k, $err, $fieldKeys, $warn);
                    break;

                case 'decision':
                    if ($outs === []) {
                        $err($k, "'{$name}' has no outgoing connections.");
                    } elseif (! in_array(null, array_map(fn ($e) => $e['condition'] ?? null, $outs), true)) {
                        $err($k, "'{$name}' needs one connection without a condition, used when no other condition matches.");
                    }
                    break;

                case 'parallel_split':
                    if (count($outs) < 2) {
                        $err($k, "'{$name}' needs at least two branches.");
                    }
                    break;

                case 'parallel_join':
                    if (count($in[$k] ?? []) < 2) {
                        $err($k, "'{$name}' needs at least two incoming branches.");
                    }
                    if (count($outs) !== 1) {
                        $err($k, "'{$name}' needs exactly one outgoing connection.");
                    }
                    if (! in_array($cfg['wait'] ?? 'all', ['all', 'any'], true)) {
                        $err($k, 'Wait must be "all" or "any".');
                    }
                    break;

                case 'automation':
                    if (! $this->actions->has((string) ($cfg['action'] ?? ''))) {
                        $err($k, "'{$name}' must use an installed automation action.");
                    }
                    if (! $has('success') && ! $has('default')) {
                        $err($k, "'{$name}' needs a connection for success.");
                    }
                    if (! empty($cfg['on_blocked'])) {
                        $this->checkAssignees([$cfg['on_blocked']], $k, $name, $err, $warn, $fieldKeys);
                    } elseif (empty($settings['fallback'])) {
                        $warn($k, "If '{$name}' cannot run, workflow supervisors will be asked to complete it. Set a person to handle it instead.");
                    }
                    break;

                case 'notification':
                    if (! NotificationTemplate::query()->where('key', $cfg['template'] ?? '')->exists()) {
                        $err($k, "'{$name}' must use an existing notification template.");
                    }
                    if (count($outs) !== 1) {
                        $err($k, "'{$name}' needs exactly one outgoing connection.");
                    }
                    break;

                case 'timer':
                    if (! (($cfg['duration'] ?? 0) > 0) || ! in_array($cfg['unit'] ?? '', BusinessTime::UNITS, true)) {
                        $err($k, "'{$name}' needs a positive duration and a unit.");
                    }
                    break;
            }
        }

        if (! empty($settings['validator']) && ! isset(config('workflow.validators')[$settings['validator']])) {
            $err(null, "Submission validator '{$settings['validator']}' is not installed.");
        }
        if (! empty($settings['fallback'])) {
            $this->checkAssignees([$settings['fallback']], null, 'Fallback', $err, $warn, $fieldKeys);
        } else {
            $warn(null, 'No fallback approver is set. If a step cannot be assigned, workflow supervisors receive it.');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function checkAssignees(array $specs, ?string $k, string $name, callable $err, callable $warn, array $fieldKeys): void
    {
        if ($specs === []) {
            $err($k, "'{$name}' needs at least one assignee rule.");
        }
        foreach ($specs as $spec) {
            $type = $spec['type'] ?? null;
            if (! in_array($type, AssigneeResolver::TYPES, true)) {
                $err($k, "'{$name}': unknown assignee type.");

                continue;
            }
            match ($type) {
                'authority' => ($a = Authority::query()->where('code', $spec['authority'] ?? '')->first())
                    ? ($a->assignments()->current()->exists() ? null : $warn($k, "Nobody holds {$a->name} today."))
                    : $err($k, "'{$name}': authority '".($spec['authority'] ?? '')."' does not exist."),
                'role' => Role::query()->where('name', $spec['role'] ?? '')->exists() ? null : $err($k, "'{$name}': role '".($spec['role'] ?? '')."' does not exist."),
                'committee' => Committee::query()->where('code', $spec['committee'] ?? '')->exists() ? null : $err($k, "'{$name}': committee does not exist."),
                'field' => in_array($spec['field'] ?? '', $fieldKeys, true) ? null : $err($k, "'{$name}': field '".($spec['field'] ?? '')."' is not on the form."),
                'user' => User::query()->whereKey($spec['user_id'] ?? 0)->exists()
                    ? $warn($k, "'{$name}' is assigned to a named person; prefer an authority so that changes of office need no workflow change.")
                    : $err($k, "'{$name}': the named user does not exist."),
                default => null,
            };
        }
    }

    private function checkSla(?array $sla, string $k, callable $err, array $fieldKeys, callable $warn): void
    {
        if (! empty($sla['due_field']) && ! in_array($sla['due_field'], $fieldKeys, true)) {
            $err($k, "The deadline field '{$sla['due_field']}' is not on the form.");
        }
        if (! $sla || empty($sla['duration'])) {
            return;
        }
        if (! ($sla['duration'] > 0) || ! in_array($sla['unit'] ?? 'working_days', BusinessTime::UNITS, true)) {
            $err($k, 'The time limit needs a positive duration and a valid unit.');
        }
        foreach ($sla['escalations'] ?? [] as $i => $level) {
            if (! (($level['after'] ?? 0) > 0)) {
                $err($k, 'Escalation level '.($i + 1).' needs "after" (time past the deadline).');
            }
            $this->checkAssignees([$level['to'] ?? []], $k, 'Escalation level '.($i + 1), $err, $warn, $fieldKeys);
        }
    }

    /** @return array<string, true> */
    private function walk(string $from, callable $next): array
    {
        $seen = [$from => true];
        $stack = [$from];
        while ($stack) {
            foreach ($next(array_pop($stack)) as $k) {
                if (! isset($seen[$k])) {
                    $seen[$k] = true;
                    $stack[] = $k;
                }
            }
        }

        return $seen;
    }
}
