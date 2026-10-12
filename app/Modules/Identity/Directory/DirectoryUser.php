<?php

namespace App\Modules\Identity\Directory;

/** A person entry read from the directory, already mapped to local field names. */
final readonly class DirectoryUser
{
    public function __construct(
        public string $username,
        public string $dn,
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $employeeId = null,
        public ?string $departmentCode = null,
        public ?string $designation = null,
        public string $category = 'staff',
        public bool $disabled = false,
    ) {}

    /**
     * Build from raw attributes (lower-cased attribute name => list of
     * values), applying the configured attribute map.
     *
     * @param  array<string, list<string>>  $attributes
     */
    public static function fromAttributes(string $dn, array $attributes): self
    {
        $map = config('directory.attributes');
        $first = function (string $field) use ($map, $attributes): ?string {
            $value = $attributes[strtolower($map[$field] ?? '')][0] ?? null;
            $value = is_string($value) ? trim($value) : null;

            return $value === '' ? null : $value;
        };

        $username = strtolower((string) ($attributes[strtolower(config('directory.login_attribute'))][0] ?? ''));
        $rawCategory = strtolower((string) $first('category'));
        $disabledConfig = config('directory.disabled');
        $disabledValues = array_map('strtolower', $disabledConfig['values'] ?? []);
        $markers = array_map('strtolower', $attributes[strtolower($disabledConfig['attribute'] ?? '')] ?? []);

        return new self(
            username: $username,
            dn: $dn,
            name: $first('name') ?? $username,
            email: ($email = $first('email')) ? strtolower($email) : null,
            phone: $first('phone'),
            employeeId: $first('employee_id'),
            departmentCode: $first('department_code'),
            designation: $first('designation'),
            category: config('directory.category_map')[$rawCategory] ?? config('directory.default_category'),
            disabled: $disabledValues !== [] && array_intersect($markers, $disabledValues) !== [],
        );
    }
}
