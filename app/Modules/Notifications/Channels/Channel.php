<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationMessage;

interface Channel
{
    /** Address for this user on this channel, or null when they have none. */
    public function address(User $user): ?string;

    /**
     * Deliver, returning the provider's message id when it gives one.
     *
     * @throws \Throwable on failure (the job retries and records the error)
     */
    public function deliver(NotificationMessage $message): ?string;
}
