<?php

namespace App\Services;

final class SchoolMailDeferred extends \RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct('school_mail_deferred');
    }
}
