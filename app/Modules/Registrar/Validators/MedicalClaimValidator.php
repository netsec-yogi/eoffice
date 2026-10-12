<?php

namespace App\Modules\Registrar\Validators;

class MedicalClaimValidator extends ClaimValidator
{
    public function __construct()
    {
        parent::__construct('medical');
    }
}
