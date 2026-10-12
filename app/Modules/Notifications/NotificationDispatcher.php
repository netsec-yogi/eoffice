<?php

namespace App\Modules\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Channels\Channel;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends template-driven notifications on every channel that has an active
 * template (NOTIF-001). Each (event, recipient, channel) is stored once --
 * the idempotency key makes a retried workflow step or SLA scan harmless
 * (NOTIF-002). Delivery is queued after the surrounding transaction
 * commits, so a failing provider never undoes a decision.
 */
class NotificationDispatcher
{
    /** @param array<string, Channel> $channels */
    public function __construct(private array $channels, private TemplateRenderer $renderer) {}

    /**
     * @param  iterable<User>  $recipients
     * @param  array<string, mixed>  $context  template variables; never secrets
     * @param  string  $eventKey  stable identity of the triggering event, e.g. "task:42:assigned"
     * @return list<NotificationMessage>
     */
    public function send(string $templateKey, iterable $recipients, array $context, string $eventKey, ?string $link = null): array
    {
        $templates = NotificationTemplate::query()->where('key', $templateKey)->where('is_active', true)->get()->keyBy('channel');
        if ($templates->isEmpty()) {
            logger()->warning('No active notification template', ['key' => $templateKey]);

            return [];
        }

        $created = [];
        $seen = [];
        foreach ($recipients as $user) {
            if (! $user instanceof User || isset($seen[$user->id]) || ! $user->canSignIn()) {
                continue;
            }
            $seen[$user->id] = true;
            $vars = $context + ['recipient' => ['name' => $user->name]];

            foreach ($templates as $channelName => $template) {
                $channel = $this->channels[$channelName] ?? null;
                if ($channel === null) {
                    continue;
                }
                $address = $channel->address($user);
                $message = $this->persist($template, $user, $channelName, $address, $vars, $eventKey, $link);
                if ($message === null) {
                    continue; // already sent for this event
                }
                $created[] = $message;

                if ($message->status === 'queued') {
                    DeliverNotification::dispatch($message->id)->onQueue('notifications')->afterCommit();
                }
            }
        }

        return $created;
    }

    private function persist(NotificationTemplate $template, User $user, string $channel, ?string $address, array $vars, string $eventKey, ?string $link): ?NotificationMessage
    {
        $message = new NotificationMessage;
        $message->forceFill([
            'uuid' => (string) Str::uuid(),
            'template_key' => $template->key,
            'channel' => $channel,
            'user_id' => $user->id,
            'recipient' => $address,
            'subject' => $this->renderer->render($template->subject, $vars),
            'body' => (string) $this->renderer->render($template->body, $vars),
            'link' => $link,
            'status' => match (true) {
                $address === null => 'skipped',
                $channel === 'in_app' => 'sent',
                default => 'queued',
            },
            'last_error' => $address === null ? 'Recipient has no address for this channel.' : null,
            'sent_at' => $channel === 'in_app' ? now() : null,
            'idempotency_key' => hash('sha256', "{$eventKey}|{$template->key}|{$user->id}|{$channel}"),
            'correlation_id' => Context::get('correlation_id'),
        ]);

        try {
            // Savepoint, so a duplicate does not poison an enclosing transaction.
            DB::transaction(fn () => $message->save());
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $message;
    }
}
