<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Storage\ClamAvScanner;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Support\Storage\RejectingScanner;
use App\Support\Storage\StorageException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FileScannerConfigurationTest extends TestCase
{
    public static function scannerPolicies(): array
    {
        return [
            'local with parsed null' => ['local', null, true],
            'local with null text' => ['local', 'null', true],
            'testing with parsed null' => ['testing', null, true],
            'testing with null text' => ['testing', 'null', true],
            'production with parsed null' => ['production', null, false],
            'production with null text' => ['production', 'null', false],
            'staging with parsed null' => ['staging', null, false],
            'staging with null text' => ['staging', 'null', false],
            'unknown environment with parsed null' => ['preview', null, false],
            'unknown environment with null text' => ['preview', 'null', false],
            'unknown scanner even in local' => ['local', 'unknown-scanner', false],
            'empty scanner even in local' => ['local', '', false],
            'boolean scanner even in local' => ['local', false, false],
        ];
    }

    #[DataProvider('scannerPolicies')]
    public function test_real_binding_only_allows_unscanned_uploads_in_local_and_testing(
        string $environment, mixed $configuredScanner, bool $allowsUpload
    ): void {
        $this->app['env'] = $environment;
        config(['storage.scanner' => $configuredScanner]);

        // Resolve the actual service-provider binding, without a scanner mock.
        $scanner = $this->app->make(FileScanner::class);
        $this->assertInstanceOf($allowsUpload ? NullScanner::class : RejectingScanner::class, $scanner);

        if (! $allowsUpload) {
            $this->expectException(StorageException::class);
        }
        $scanner->scan('unused-test-disk', 'unused-test-file.png');
    }

    public function test_clamav_configuration_keeps_the_real_scanner_in_every_environment(): void
    {
        config(['storage.scanner' => 'clamav']);

        foreach (['local', 'testing', 'production', 'staging', 'preview'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertInstanceOf(ClamAvScanner::class, $this->app->make(FileScanner::class));
        }
    }
}
