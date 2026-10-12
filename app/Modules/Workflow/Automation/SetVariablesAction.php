<?php

namespace App\Modules\Workflow\Automation;

use App\Modules\Notifications\TemplateRenderer;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/** Writes computed values into the instance variables, e.g. an approved amount. */
class SetVariablesAction implements AutomationAction
{
    public function __construct(private TemplateRenderer $renderer) {}

    public function label(): string
    {
        return 'Set variables';
    }

    public function settings(): array
    {
        return ['values' => ['label' => 'Variables (name => template, e.g. {{ amount }})', 'type' => 'map', 'required' => true]];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $context = ['data' => $instance->data, 'vars' => $instance->variables, ...$instance->data];
        $output = [];
        foreach ((array) ($config['values'] ?? []) as $name => $template) {
            $output[$name] = $this->renderer->render((string) $template, $context);
        }

        return AutomationResult::success($output);
    }
}
