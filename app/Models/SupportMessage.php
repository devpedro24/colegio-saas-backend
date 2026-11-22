<?php

namespace App\Models;

use App\Models\Rbac\CentralModel;

final class SupportMessage extends CentralModel
{
    protected $guarded = [];
    protected $hidden = ['id', 'tenant_id', 'ticket_id', 'author_id', 'request_key'];
    protected $casts = ['author_id' => 'integer'];
}
