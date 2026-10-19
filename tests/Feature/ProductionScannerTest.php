<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Storage\FileScanner;
use App\Support\Storage\RejectingScanner;
use Tests\TestCase;

class ProductionScannerTest extends TestCase
{
    public function test_production_without_configured_scanner_rejects_uploads(): void
    {
        $previousEnvironment = app()->environment();
        $previousScanner = config('storage.scanner');

        try {
            app()->detectEnvironment(fn () => 'production');
            config(['storage.scanner' => 'null']);
            $this->assertInstanceOf(RejectingScanner::class, app(FileScanner::class));

            config(['storage.scanner' => 'unrecognized']);
            $this->assertInstanceOf(RejectingScanner::class, app(FileScanner::class));
        } finally {
            app()->detectEnvironment(fn () => $previousEnvironment);
            config(['storage.scanner' => $previousScanner]);
        }
    }
}
