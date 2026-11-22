<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Rbac\CentralModel;

final class LoginAppearance extends CentralModel
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
