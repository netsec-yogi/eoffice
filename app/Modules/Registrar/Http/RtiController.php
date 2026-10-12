<?php

namespace App\Modules\Registrar\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Permissions;
use App\Modules\Registrar\Models\RtiRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** RTI register with statutory clocks (REG-004). */
class RtiController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::RTI_MANAGE);
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'overdue', 'replied', 'transferred', 'appeals'])], 'q' => ['nullable', 'string', 'max:100']]);

        $rows = RtiRequest::query()->with('pio:id,name')
            ->when(($f['status'] ?? null) === 'overdue', fn ($q) => $q->where('status', 'open')->where('due_on', '<', today()))
            ->when(in_array($f['status'] ?? null, ['open', 'replied', 'transferred'], true), fn ($q) => $q->where('status', $f['status']))
            ->when(($f['status'] ?? null) === 'appeals', fn ($q) => $q->where('appeal_status', '!=', 'none'))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('registration_number', 'ilike', "%{$t}%")->orWhere('applicant_name', 'ilike', "%{$t}%")->orWhere('subject', 'ilike', "%{$t}%")))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->orderBy('due_on')->paginate(30)->withQueryString()
            ->through(fn (RtiRequest $r) => [
                ...$r->only('id', 'registration_number', 'applicant_name', 'subject', 'status', 'outcome', 'life_liberty', 'appeal_status', 'workflow_instance_id', 'appeal_instance_id', 'transferred_to'),
                'pio' => $r->pio?->name,
                'received_on' => $r->received_on->toDateString(), 'due_on' => $r->due_on->toDateString(), 'replied_on' => $r->replied_on?->toDateString(),
                'days_left' => $r->status === 'open' ? (int) today()->diffInDays($r->due_on, false) : null,
                'late' => $r->replied_on ? $r->replied_on->gt($r->due_on) : ($r->status === 'open' && $r->due_on->isPast()),
                'appeal_due_on' => $r->appeal_due_on?->toDateString(),
            ]);

        return Inertia::render('Rti/Index', [
            'requests' => $rows,
            'filters' => $f,
            'stats' => [
                'open' => RtiRequest::query()->where('status', 'open')->count(),
                'overdue' => RtiRequest::query()->where('status', 'open')->where('due_on', '<', today())->count(),
                'due_week' => RtiRequest::query()->where('status', 'open')->whereBetween('due_on', [today(), today()->addDays(7)])->count(),
                'appeals_open' => RtiRequest::query()->where('appeal_status', 'filed')->count(),
                'replied_late' => RtiRequest::query()->whereNotNull('replied_on')->whereColumn('replied_on', '>', 'due_on')->count(),
            ],
            'rules' => config('registrar.rti'),
        ]);
    }
}
