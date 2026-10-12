<?php

namespace App\Modules\Registrar\Validators;

class LtcAuthorisationValidator extends TravelAuthorisationValidator
{
    public function __construct()
    {
        parent::__construct('ltc');
    }
}
