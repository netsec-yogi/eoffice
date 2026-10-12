<?php

namespace App\Modules\Notifications;

/** Replaces {{ path.to.value }} placeholders. No logic, no code execution. */
class TemplateRenderer
{
    /** @param array<string, mixed> $context */
    public function render(?string $template, array $context): ?string
    {
        if ($template === null) {
            return null;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($context) {
            $value = data_get($context, $m[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $template);
    }
}
