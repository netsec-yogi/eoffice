<?php

namespace App\Modules\Workflow\Forms;

use App\Modules\Identity\Models\User;

/**
 * Live option lists for select fields (`"source": "software_titles"`).
 * Modules register sources in config/workflow.php (option_sources). The
 * server resolves them both for rendering and for validation, so a form
 * never offers or accepts a value the register does not contain.
 */
class OptionSources
{
    /** @return list<array{value: string, label: string}> */
    /** Sources may depend on who is filling the form ("my approved tours"). */
    public function options(string $source, ?User $user = null): array
    {
        // Keys may contain dots, so never interpolate them into a config path.
        $resolver = config('workflow.option_sources')[$source] ?? null;

        return $resolver ? array_values(app($resolver)($user)) : [];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('workflow.option_sources', []));
    }

    /** The schema with every sourced field's options filled in. */
    public function resolve(array $schema, ?User $user = null): array
    {
        foreach ($schema['sections'] ?? [] as $s => $section) {
            foreach ($section['fields'] ?? [] as $f => $field) {
                if (! empty($field['source'])) {
                    $schema['sections'][$s]['fields'][$f]['options'] = $this->options($field['source'], $user);
                }
            }
        }

        return $schema;
    }
}
