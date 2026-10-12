<?php

namespace App\Modules\Registrar\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'title', 'body', 'numbering_pattern', 'footer', 'is_active'])]
class CertificateTemplate extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
