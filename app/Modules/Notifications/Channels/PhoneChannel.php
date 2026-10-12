<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SMS and WhatsApp Business. Drivers:
 *  - log: development only; writes the message to the log and says so.
 *  - anything else: a provider adapter must be registered in
 *    NotificationsServiceProvider; until then delivery fails with
 *    ChannelNotConfigured (docs/SECURITY.md R-3).
 */
class PhoneChannel implements Channel
{
    public function __construct(private string $channel, private string $driver) {}

    public function address(User $user): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $user->phone);

        return $digits !== '' ? $digits : null;
    }

    public function deliver(NotificationMessage $message): ?string
    {
        if ($this->driver !== 'log') {
            throw new ChannelNotConfigured("No {$this->channel} provider adapter for driver [{$this->driver}].");
        }

        Log::info("[{$this->channel}:log-driver] message not sent to a provider", [
            'to' => Str::mask((string) $message->recipient, '*', 2, -2),
            'uuid' => $message->uuid,
        ]);

        return 'log-'.$message->uuid;
    }
}
