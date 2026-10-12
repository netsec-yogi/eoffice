<?php

namespace App\Modules\Workflow\Automation;

/** The automation actions this installation offers (config/workflow.php). */
class ActionRegistry
{
    public function has(string $key): bool
    {
        return isset(config('workflow.actions')[$key]);
    }

    public function make(string $key): ?AutomationAction
    {
        $class = config('workflow.actions')[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** @return array<string, array{label: string, settings: array}> */
    public function catalogue(): array
    {
        $out = [];
        foreach (array_keys(config('workflow.actions')) as $key) {
            $action = $this->make($key);
            $out[$key] = ['label' => $action->label(), 'settings' => $action->settings()];
        }

        return $out;
    }
}
