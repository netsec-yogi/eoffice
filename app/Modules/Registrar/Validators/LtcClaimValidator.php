<?php

namespace App\Modules\Registrar\Validators;

class LtcClaimValidator extends ClaimValidator
{
    public function __construct()
    {
        parent::__construct('ltc');
    }
}
