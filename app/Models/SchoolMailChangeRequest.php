<?php

namespace App\Models;

use App\Models\Rbac\CentralModel;

class SchoolMailChangeRequest extends CentralModel
{
    protected $table = 'school_mail_change_requests';

    protected $guarded = [];

    protected $hidden = ['id', 'tenant_id', 'requester_id', 'reviewer_id', 'revision'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
