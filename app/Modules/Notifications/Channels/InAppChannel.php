<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationMessage;

/** In-app notifications are the stored message itself. */
class InAppChannel implements Channel
{
    public function address(User $user): ?string
    {
        return 'user:'.$user->id;
    }

    public function deliver(NotificationMessage $message): ?string
    {
        return null;
    }
}
