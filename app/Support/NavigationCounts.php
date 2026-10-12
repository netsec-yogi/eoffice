<?php

namespace App\Support;

use App\Modules\EFile\Models\EFileMovement;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Registrar\Models\CircularReceipt;
use App\Modules\Workflow\Models\WorkflowTask;

/** Badge counts for the sidebar, computed per request for the signed-in user. */
class NavigationCounts
{
    /** @return array<string, int> */
    public function for(User $user): array
    {
        return [
            'tasks' => WorkflowTask::query()->where('assignee_id', $user->id)->where('status', 'pending')
                ->whereHas('instance', fn ($q) => $q->where('status', 'in_progress'))->count(),
            'files' => EFileMovement::query()->where('to_user_id', $user->id)->where('status', 'in_transit')->count(),
            'circulars' => CircularReceipt::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            'notifications' => NotificationMessage::query()
                ->where('user_id', $user->id)->where('channel', 'in_app')->whereNull('read_at')->count(),
        ];
    }
}
