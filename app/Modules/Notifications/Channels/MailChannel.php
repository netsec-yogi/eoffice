<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationMessage;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class MailChannel implements Channel
{
    public function address(User $user): ?string
    {
        return $user->email;
    }

    public function deliver(NotificationMessage $message): ?string
    {
        $body = $message->body.($message->link ? "\n\nOpen: ".url($message->link) : '');

        Mail::raw($body, function (Message $mail) use ($message) {
            $mail->to($message->recipient)->subject($message->subject ?? config('app.name'));
            $mail->getHeaders()->addTextHeader('X-Correlation-ID', (string) $message->correlation_id);
        });

        return null;
    }
}
