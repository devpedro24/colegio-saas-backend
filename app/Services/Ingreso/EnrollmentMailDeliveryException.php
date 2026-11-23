<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

final class EnrollmentMailDeliveryException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
