<?php

declare(strict_types=1);

// Opt-in local smoke check. Never prints/decrypts credentials to output or changes settings.
use App\Http\Controllers\Api\SchoolMailController;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SchoolMail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "No se completó la verificación de correo. No se modificó la conexión.\n");
    exit(1);
});
if (PHP_SAPI !== 'cli' || ! $app->environment('local') || ! in_array('--verify', $argv, true)) {
    exit(1);
}
$slug = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $slug = substr($arg, 9);
    }
}
if (! is_string($slug) || ! preg_match('/^[a-z][a-z0-9-]*$/D', $slug)) {
    exit(1);
}
Tenant::where('slug', $slug)->firstOrFail()->run(function () use ($argv): void {
    $before = DB::table('correo_configuracion')->where('key', 'gmail')->first();
    if (! $before) {
        throw new RuntimeException;
    }
    $rector = User::where('role', 'rector')->where('status', 'active')->firstOrFail();
    $request = Request::create('/api/correo-institucional');
    $request->setUserResolver(fn () => $rector);
    $state = app(SchoolMailController::class)->show($request, app(SchoolMail::class))->getData(true);
    if (! $state['configurado'] || ! $state['requiere_autorizacion'] || $state['puede_editar'] || $state['puede_desconectar']) {
        throw new RuntimeException;
    }
    $sent = null; // Not requested is different from a failed SMTP test.
    if (in_array('--send-test', $argv, true)) {
        app(SchoolMail::class)->test(app(SchoolMail::class)->settings());
        $sent = true;
    }
    $after = DB::table('correo_configuracion')->where('key', 'gmail')->first();
    if (json_encode($before) !== json_encode($after)) {
        throw new RuntimeException;
    }
    echo json_encode(['connection_configured' => true, 'locked_after_reload' => true,
        'smtp_test_accepted' => $sent, 'settings_and_encrypted_credential_unchanged' => true], JSON_PRETTY_PRINT).PHP_EOL;
});
