<?php

namespace App\Modules\Workflow\Automation;

final readonly class AutomationResult
{
    /** @param array<string, mixed> $output merged into the instance variables (vars.*) */
    private function __construct(public string $status, public array $output = [], public ?string $message = null) {}

    public static function success(array $output = [], ?string $message = null): self
    {
        return new self('succeeded', $output, $message);
    }

    /** The target system refused or failed; the workflow takes its failure path. */
    public static function failure(string $message, array $output = []): self
    {
        return new self('failed', $output, $message);
    }

    /** The integration cannot run here (not configured, no credentials): a person must do it. */
    public static function blocked(string $message): self
    {
        return new self('blocked', [], $message);
    }
}
