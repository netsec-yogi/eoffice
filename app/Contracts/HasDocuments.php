<?php

namespace App\Contracts;

use App\Modules\Identity\Models\User;

/**
 * A record that owns documents decides who may read them. DocumentPolicy
 * asks the owner on every download; there is no other path to a file.
 */
interface HasDocuments
{
    public function documentsViewableBy(User $user): bool;
}
