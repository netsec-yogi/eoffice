<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'channel', 'subject', 'body', 'provider_template_id', 'is_active'])]
class NotificationTemplate extends Model
{
    public const CHANNELS = ['mail', 'in_app', 'sms', 'whatsapp'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
