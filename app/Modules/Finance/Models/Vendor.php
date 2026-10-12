<?php

namespace App\Modules\Finance\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'gstin', 'pan', 'address', 'email', 'phone', 'bank_account', 'ifsc', 'msme', 'is_active'])]
#[Hidden(['bank_account'])]
class Vendor extends Model
{
    protected function casts(): array
    {
        return ['bank_account' => 'encrypted', 'msme' => 'boolean', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The vendor record behind a portal account, if any. */
    public static function forUser(User $user): ?self
    {
        return $user->category === 'vendor' ? self::query()->where('user_id', $user->id)->first() : null;
    }

    public function maskedAccount(): ?string
    {
        return $this->bank_account ? str_repeat('•', max(0, strlen($this->bank_account) - 4)).substr($this->bank_account, -4) : null;
    }
}
