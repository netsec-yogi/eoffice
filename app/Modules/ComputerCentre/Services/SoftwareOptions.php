<?php

namespace App\Modules\ComputerCentre\Services;

use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\Identity\Models\User;

/** Option source "software_titles": active, unexpired software in the licence register. */
class SoftwareOptions
{
    public function __invoke(?User $user = null): array
    {
        return SoftwareTitle::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', today()))
            ->orderBy('name')->get(['code', 'name'])
            ->map(fn ($t) => ['value' => $t->code, 'label' => $t->name])->all();
    }
}
