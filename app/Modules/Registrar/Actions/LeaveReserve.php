<?php

namespace App\Modules\Registrar\Actions;

use App\Modules\Registrar\Services\LeaveService;

class LeaveReserve extends LeaveActions
{
    public function __construct(LeaveService $leave)
    {
        parent::__construct($leave, 'reserve');
    }
}
