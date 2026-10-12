<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\NotificationMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in user's in-app notifications. */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $messages = NotificationMessage::query()->where('user_id', $request->user()->id)->where('channel', 'in_app')
            ->latest('id')->paginate(30)
            ->through(fn (NotificationMessage $m) => [
                'id' => $m->id, 'subject' => $m->subject, 'body' => $m->body, 'link' => $m->link,
                'read' => $m->read_at !== null, 'at' => $m->created_at->toIso8601String(),
            ]);

        return Inertia::render('Notifications/Index', ['messages' => $messages]);
    }

    public function read(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']])['ids'] ?? null;
        NotificationMessage::query()->where('user_id', $request->user()->id)->where('channel', 'in_app')->whereNull('read_at')
            ->when($ids, fn ($q) => $q->whereKey($ids))->update(['read_at' => now()]);

        return back();
    }
}
