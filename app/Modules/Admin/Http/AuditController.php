<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Permissions;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only audit search for authorised auditors (ADMIN-002). */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::AUDIT_VIEW);
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'outcome' => ['nullable', Rule::in(['success', 'failure', 'denied'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'correlation' => ['nullable', 'uuid'],
        ]);

        $events = AuditEvent::query()
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($filters['actor'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', Carbon::parse($v)->addDay()))
            ->when($filters['correlation'] ?? null, fn ($q, $v) => $q->where('correlation_id', $v))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEvent $e) => [
                'id' => $e->id,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'actor' => $e->actor_label,
                'actor_id' => $e->actor_id,
                'action' => $e->action,
                'subject' => $e->subject_type ? "{$e->subject_type}#{$e->subject_id}" : null,
                'outcome' => $e->outcome,
                'ip_address' => $e->ip_address,
                'correlation_id' => $e->correlation_id,
                'context' => $e->context,
                'hash' => substr($e->hash, 0, 12),
            ]);

        return Inertia::render('Admin/Audit/Index', [
            'events' => $events,
            'filters' => $filters,
            'subjectTypes' => array_keys(config('morph')),
        ]);
    }

    public function verify(): RedirectResponse
    {
        $this->authorize(Permissions::AUDIT_VIEW);
        $ok = Artisan::call('audit:verify') === 0;
        $output = trim(Artisan::output());

        return back()->with($ok ? 'success' : 'error', $output);
    }
}
