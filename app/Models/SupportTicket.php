<?php

namespace App\Models;

use App\Models\Rbac\CentralModel;

final class SupportTicket extends CentralModel
{
    protected $guarded = [];
    protected $hidden = ['id', 'tenant_id', 'requester_id', 'request_key'];
    protected $casts = ['priority' => 'boolean', 'version' => 'integer', 'requester_id' => 'integer'];
}
