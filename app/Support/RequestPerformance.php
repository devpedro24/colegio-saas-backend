<?php

declare(strict_types=1);

namespace App\Support;

/** Request-scoped counters only: no SQL, bindings, IDs or personal data retained. */
final class RequestPerformance
{
    public bool $active = false;
    public int $queries = 0;
    public float $databaseMs = 0;

    public function begin(): void
    {
        $this->active = true;
        $this->queries = 0;
        $this->databaseMs = 0;
    }

    public function record(float $milliseconds): void
    {
        if ($this->active) {
            $this->queries++;
            $this->databaseMs += $milliseconds;
        }
    }
}
