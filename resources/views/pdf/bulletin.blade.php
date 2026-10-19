<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $document['plantilla']['configuracion']['heading'] }}</title>
    <style>
        @page { margin: 28px 34px 35px; }
        body { color: #16243b; font-family: {{ $document['plantilla']['configuracion']['font'] === 'serif' ? 'DejaVu Serif' : 'DejaVu Sans' }}, sans-serif; font-size: {{ $document['plantilla']['configuracion']['density'] === 'compact' ? '9' : '10' }}px; line-height: 1.45; }
        .masthead { border-bottom: 3px solid {{ $document['plantilla']['configuracion']['accent'] }}; padding-bottom: 12px; margin-bottom: 20px; }
        .masthead table { border: 0; width: 100%; }
        .masthead td { border: 0; vertical-align: middle; }
        .logo { max-width: 75px; max-height: 75px; }
        h1 { color: {{ $document['plantilla']['configuracion']['accent'] }}; font-size: 19px; margin: 5px 0 2px; }
        h2 { color: {{ $document['plantilla']['configuracion']['accent'] }}; font-size: 12px; margin: 16px 0 6px; }
        p { margin: 3px 0; }
        .muted { color: #586983; }
        .school { font-size: 16px; font-weight: 700; }
        .meta { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
        .meta td { background: #f3f6fa; padding: 8px; width: 50%; border: 1px solid #dce4ec; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid th { background: {{ $document['plantilla']['configuracion']['accent'] }}; color: #fff; text-align: left; }
        .grid th, .grid td { padding: 6px 8px; border: 1px solid #dce4ec; }
        .grid tr:nth-child(even) td { background: #f6f8fb; }
        .box { background: #f3f6fa; border-left: 3px solid {{ $document['plantilla']['configuracion']['accent'] }}; padding: 10px 12px; }
        .foot { position: fixed; bottom: -23px; right: 0; color: #64748b; font-size: 8px; }
    </style>
</head>
<body>
    <div class="masthead"><table><tr>
        @if($logo)<td style="width:90px"><img class="logo" src="{{ $logo }}" alt=""></td>@endif
        <td><div class="school">{{ $document['colegio']['nombre'] }}</div>
            @if($document['colegio']['nit'])<div>NIT {{ $document['colegio']['nit'] }}</div>@endif
            @if($document['colegio']['resolucion'])<div class="muted">{{ $document['colegio']['resolucion'] }}</div>@endif
            @if($document['colegio']['direccion'])<div class="muted">{{ $document['colegio']['direccion'] }}</div>@endif
        </td>
    </tr></table></div>
    <h1>{{ $document['plantilla']['configuracion']['heading'] }}</h1>
    <p class="muted">{{ $document['plantilla']['configuracion']['subheading'] }}</p>
    <p><strong>{{ $document['tipo'] === 'final' ? 'Informe final' : $document['periodo']['nombre'] }}</strong> · Año lectivo {{ $document['ano'] }}</p>
    @foreach($document['plantilla']['configuracion']['sections'] as $section)
        @if(!$section['visible']) @continue @endif
        @switch($section['key'])
            @case('student')
                <h2>Estudiante</h2>
                <table class="meta"><tr><td><strong>Nombre</strong><br>{{ $document['estudiante']['nombre'] }}</td><td><strong>Grado y grupo</strong><br>{{ $document['estudiante']['grado'] }} · {{ $document['estudiante']['grupo'] }}</td></tr></table>
                @break
            @case('areas')
                @if(count($document['areas']))
                    <h2>Áreas</h2><table class="grid"><thead><tr><th>Área</th><th>Desempeño</th></tr></thead><tbody>
                    @foreach($document['areas'] as $row)<tr><td>{{ $row['nombre'] }}</td><td>{{ $row['resultado']['display_value'] ?? $row['resultado']['valoracion']['etiqueta'] ?? 'Calculado' }}</td></tr>@endforeach
                    </tbody></table>
                @endif
                @break
            @case('subjects')
                <h2>Asignaturas</h2><table class="grid"><thead><tr><th>Asignatura</th><th>Desempeño</th></tr></thead><tbody>
                @foreach($document['asignaturas'] as $row)<tr><td>{{ $row['nombre'] }}</td><td>{{ $row['resultado']['display_value'] ?? $row['resultado']['valoracion']['etiqueta'] ?? 'Calculado' }}</td></tr>@endforeach
                </tbody></table>
                @break
            @case('indicators')
                @if(count($document['indicadores']))
                    <h2>Indicadores de aprendizaje</h2><table class="grid"><thead><tr><th>Período</th><th>Asignatura</th><th>Aprendizaje esperado</th></tr></thead><tbody>
                    @foreach($document['indicadores'] as $row)<tr><td>{{ $row['periodo'] }}</td><td>{{ $row['materia'] }}</td><td>{{ $row['texto'] }}</td></tr>@endforeach
                    </tbody></table>
                @endif
                @break
            @case('observations')
                @if($document['observacion'])<h2>Observación</h2><div class="box">{{ $document['observacion'] }}</div>@endif
                @if($document['promocion'])<h2>Resultado final</h2><div class="box">{{ $document['promocion']['resultado'] }}</div>@endif
                @break
            @case('attendance')
                <h2>Asistencia registrada</h2><p>Registros: {{ $document['asistencia']['registradas'] }} · Ausencias: {{ $document['asistencia']['ausentes'] }} · Tardanzas: {{ $document['asistencia']['tardes'] }} · Justificadas: {{ $document['asistencia']['justificadas'] }}</p>
                @break
            @case('signature')
                <h2>Validación institucional</h2><p>________________________________<br>Firma autorizada</p>
                @break
        @endswitch
    @endforeach
    <div class="foot">Documento institucional · {{ $document['plantilla']['nombre'] }}</div>
</body>
</html>
