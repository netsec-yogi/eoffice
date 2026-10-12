<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Permissions;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Notification templates and delivery monitoring (NOTIF-001/002, ADMIN-003). */
class NotificationAdminController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permissions::NOTIFICATIONS_MANAGE);
        $status = $request->validate(['status' => ['nullable', Rule::in(['queued', 'sent', 'failed', 'skipped'])]])['status'] ?? 'failed';

        return Inertia::render('Admin/Notifications/Index', [
            'templates' => NotificationTemplate::query()->orderBy('key')->orderBy('channel')->get(),
            'channels' => NotificationTemplate::CHANNELS,
            'status' => $status,
            'counts' => NotificationMessage::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'messages' => NotificationMessage::query()->where('status', $status)->where('channel', '!=', 'in_app')
                ->with('user:id,name,username')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (NotificationMessage $m) => [
                    ...$m->only('id', 'template_key', 'channel', 'status', 'attempts', 'last_error', 'correlation_id'),
                    'user' => $m->user?->only('id', 'name', 'username'),
                    'created_at' => $m->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::NOTIFICATIONS_MANAGE);
        $data = $this->validated($request);
        $request->validate(['key' => [Rule::unique('notification_templates')->where('channel', $data['channel'])]]);
        $template = NotificationTemplate::create($data);
        $this->audit->record('admin.notification_template.created', $template, $template->only('key', 'channel'));

        return back()->with('success', 'Template created.');
    }

    public function update(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $this->authorize(Permissions::NOTIFICATIONS_MANAGE);
        $template->fill($this->validated($request, $template));
        $template->forceFill(['updated_by' => $request->user()->id]);
        $changes = array_keys($template->getDirty());
        $template->save();
        $this->audit->record('admin.notification_template.updated', $template, ['fields' => $changes]);

        return back()->with('success', 'Template saved.');
    }

    public function retry(NotificationMessage $message): RedirectResponse
    {
        $this->authorize(Permissions::NOTIFICATIONS_MANAGE);
        abort_unless($message->status === 'failed', 409, 'Only failed messages can be retried.');
        $message->forceFill(['status' => 'queued', 'last_error' => null])->save();
        DeliverNotification::dispatch($message->id)->onQueue('notifications');
        $this->audit->record('admin.notification.retried', null, ['message_id' => $message->id]);

        return back()->with('success', 'Message queued again.');
    }

    private function validated(Request $request, ?NotificationTemplate $template = null): array
    {
        return $request->validate([
            'key' => [$template ? 'prohibited' : 'required', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/'],
            'channel' => [$template ? 'prohibited' : 'required', Rule::in(NotificationTemplate::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'provider_template_id' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
