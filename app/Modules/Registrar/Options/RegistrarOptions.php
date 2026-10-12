<?php

namespace App\Modules\Registrar\Options;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Models\RtiRequest;
use App\Modules\Registrar\Models\TravelAuthorisation;

/** Option sources for Registrar forms; most depend on who is filling the form. */
class RegistrarOptions
{
    public function __construct(private string $source) {}

    public function __invoke(?User $user = null): array
    {
        return match ($this->source) {
            'leave_types' => LeaveType::query()->where('is_active', true)->orderBy('name')->get()
                ->filter(fn ($t) => ! $user || $t->eligible($user->category))
                ->map(fn ($t) => ['value' => $t->code, 'label' => $t->name])->values()->all(),
            'my_leaves' => $user ? LeaveRecord::query()->with('type:id,name')->where('user_id', $user->id)->whereIn('status', ['approved', 'curtailed'])
                ->where('to_date', '>=', today()->subDays(30))->orderBy('from_date')->get()
                ->map(fn ($r) => ['value' => (string) $r->id, 'label' => "{$r->type->name}: {$r->from_date->format('d M Y')} – {$r->to_date->format('d M Y')} ({$r->days} d)"])->all() : [],
            'my_tours', 'my_ltc' => $user ? TravelAuthorisation::query()->where(['user_id' => $user->id, 'kind' => $this->source === 'my_tours' ? 'tour' : 'ltc', 'status' => 'approved', 'claim_status' => 'none'])
                ->orderByDesc('from_date')->get()
                ->map(fn ($a) => ['value' => (string) $a->id, 'label' => "{$a->destination}: {$a->from_date->format('d M Y')} – {$a->to_date->format('d M Y')}"])->all() : [],
            'rti_requests' => RtiRequest::query()->orderByDesc('received_on')->limit(500)->get()
                ->map(fn ($r) => ['value' => (string) $r->id, 'label' => "{$r->registration_number} — {$r->applicant_name}: ".mb_strimwidth($r->subject, 0, 60, '…')])->all(),
            default => [],
        };
    }
}
