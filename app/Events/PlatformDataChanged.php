<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un dato de plataforma cambio (colegios, planes o catálogo RBAC). Se emite al
 * canal privado `platform` para que los paneles del superadmin se actualicen en
 * vivo entre sesiones.
 */
class PlatformDataChanged
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param string $resource 'colegios' | 'plans' | 'rbac'
     * @param string $action   'created' | 'updated' | 'deleted' | 'disabled' | 'enabled'
     */
    public function __construct(public string $resource, public string $action = 'updated') {}

}
