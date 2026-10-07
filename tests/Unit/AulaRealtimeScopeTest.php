<?php

namespace Tests\Unit;

use App\Support\Realtime\RealtimeChanges;
use PHPUnit\Framework\TestCase;

class AulaRealtimeScopeTest extends TestCase
{
    public function test_aula_writes_never_broadcast_as_global_changes(): void
    {
        $this->assertSame('aula-grade', RealtimeChanges::resourceForPath('api/aula/secciones/token/recursos'));
        $this->assertSame('aula-grade', RealtimeChanges::resourceForPath('api/aula/intentos/token/finalizar'));
        $this->assertSame('aula', RealtimeChanges::resourceForPath('api/aula/recursos/token/archivar'));
        $this->assertSame('aula-progress', RealtimeChanges::resourceForPath('api/aula/recursos/token/abrir'));
        foreach (['recursos/token/adjuntos', 'entregas/token/adjuntos', 'adjuntos/token'] as $path) {
            $this->assertSame('aula-content', RealtimeChanges::resourceForPath('api/aula/'.$path));
        }
        foreach (['respuestas', 'pagina', 'incidentes'] as $action) {
            $this->assertSame('aula-attempt',
                RealtimeChanges::resourceForPath('api/aula/intentos/token/'.$action));
        }
    }
}
