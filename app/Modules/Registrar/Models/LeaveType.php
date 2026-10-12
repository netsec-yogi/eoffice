<?php

namespace App\Modules\Registrar\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'description', 'has_balance', 'counts_holidays', 'allow_half_day', 'max_days_per_application', 'min_notice_days', 'certificate_after_days', 'eligible_categories', 'is_active'])]
class LeaveType extends Model
{
    protected function casts(): array
    {
        return [
            'has_balance' => 'boolean', 'counts_holidays' => 'boolean', 'allow_half_day' => 'boolean', 'is_active' => 'boolean',
            'eligible_categories' => 'array', 'max_days_per_application' => 'float', 'certificate_after_days' => 'float', 'min_notice_days' => 'integer',
        ];
    }

    public function eligible(string $category): bool
    {
        return $this->eligible_categories === null || $this->eligible_categories === [] ? in_array($category, ['faculty', 'staff'], true) : in_array($category, $this->eligible_categories, true);
    }
}
