<?php

namespace App\Services;

use Illuminate\Contracts\Mail\Factory;

/** Resolve current tenant at send time, not when the notification channel was registered. */
class SchoolMailFactory implements Factory
{
    public function mailer($name = null)
    {
        return app(SchoolMail::class)->mailer();
    }
}
