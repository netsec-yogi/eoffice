<?php

namespace App\Modules\Documents;

use App\Contracts\HasDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if (in_array($document->scan_status, ['infected', 'destroyed'], true)) {
            return false;
        }

        $owner = $document->attachable;
        if ($owner instanceof HasDocuments) {
            return $owner->documentsViewableBy($user);
        }

        // Not yet attached (draft upload): only the uploader.
        return $document->attachable_id === null && $document->uploaded_by === $user->id;
    }
}
