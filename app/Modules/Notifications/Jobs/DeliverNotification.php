<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Channels\ChannelNotConfigured;
use App\Modules\Notifications\Models\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message = NotificationMessage::query()->find($this->messageId);
        if (! $message || $message->status !== 'queued') {
            return; // sent, skipped or failed already: never send twice
        }

        $channel = app('notifications.channels')[$message->channel] ?? null;
        $message->forceFill(['attempts' => $message->attempts + 1])->save();

        try {
            if ($channel === null) {
                throw new ChannelNotConfigured("Unknown channel [{$message->channel}].");
            }
            $providerId = $channel->deliver($message);
            $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $providerId, 'last_error' => null])->save();
        } catch (ChannelNotConfigured $e) {
            $message->forceFill(['status' => 'failed', 'last_error' => $e->getMessage()])->save();
        } catch (Throwable $e) {
            $message->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e; // let the queue retry with backoff
        }
    }

    public function failed(?Throwable $e): void
    {
        NotificationMessage::query()->whereKey($this->messageId)->where('status', 'queued')
            ->update(['status' => 'failed', 'last_error' => mb_substr((string) $e?->getMessage(), 0, 1000)]);
    }
}
