<?php

namespace App\Modules\ComputerCentre\Integrations;

final readonly class ProvisionResult
{
    private function __construct(public string $status, public ?string $identifier = null, public ?string $message = null, public array $details = []) {}

    public static function ok(string $identifier, array $details = [], ?string $message = null): self
    {
        return new self('ok', $identifier, $message, $details);
    }

    public static function failed(string $message): self
    {
        return new self('failed', null, $message);
    }

    public static function notConfigured(string $what): self
    {
        return new self('not_configured', null, "{$what} is not configured on this installation.");
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
