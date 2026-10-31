<?php

namespace Tests\Unit;

use App\Support\Realtime\RealtimeChanges;
use PHPUnit\Framework\TestCase;

class BulletinRealtimeScopeTest extends TestCase
{
    public function test_bulletin_writes_never_broadcast_as_global_changes(): void
    {
        foreach (['emisiones/token/emitir' => 'bulletin-issues', 'emisiones/documentos/token/publicar' => 'bulletin-issues',
            'observaciones/token' => 'bulletin-observations', 'observaciones/token/aprobar' => 'bulletin-observations',
            'plantillas/token/publicar' => 'bulletin-templates', 'politica' => 'bulletins'] as $path => $resource) {
            $this->assertSame($resource, RealtimeChanges::resourceForPath('/api/boletines/'.$path.'?opaque=1'));
        }
    }
}
