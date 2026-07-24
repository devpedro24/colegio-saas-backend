<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea un Superadministrador de la Plataforma (ROL-01) en la BD CENTRAL.
 *
 * El superadmin no pertenece a ningun colegio (tenant): vive en la plataforma
 * y se autentica en el dominio central (localhost), no por subdominio.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'superadmin:create
        {email : Correo del superadministrador}
        {--name=Superadministrador : Nombre a mostrar}
        {--password= : Contrasena (si se omite, se genera una)}';

    protected $description = 'Crea un Superadministrador de la Plataforma en la BD central';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el correo '{$email}' en la plataforma.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(14));

        User::create([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $this->info('Superadministrador creado.');
        $this->table(['Campo', 'Valor'], [
            ['Correo', $email],
            ['Contrasena', $password],
            ['Ingreso', 'http://localhost:5173'],
        ]);

        return self::SUCCESS;
    }
}
