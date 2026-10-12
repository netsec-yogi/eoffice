<?php

namespace App\Modules\EFile\Services;

use App\Modules\EFile\Models\Correspondence;
use App\Modules\EFile\Models\EFile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;

class EFilePolicy
{
    public function __construct(private FileAccess $access) {}

    public function view(User $user, EFile|Correspondence $record): bool
    {
        if ($record instanceof Correspondence) {
            return $this->viewCorrespondence($user, $record);
        }

        return $this->access->allows($user, $record);
    }

    /** Correspondence: the registry, the person it is marked to, and readers of the file it sits on. */
    private function viewCorrespondence(User $user, Correspondence $c): bool
    {
        if ($c->registered_by === $user->id || $c->marked_to === $user->id) {
            return true;
        }
        if ($c->efile_id && ($file = EFile::query()->find($c->efile_id)) && $this->access->allows($user, $file)) {
            return true;
        }

        return ! in_array($c->classification, ['confidential', 'restricted'], true) && $user->can(Permissions::EFILES_CORRESPONDENCE);
    }
}
