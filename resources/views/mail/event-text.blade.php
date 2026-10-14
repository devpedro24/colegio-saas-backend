{{ $event['school'] }}
{{ $english ? 'New school event' : 'Nuevo evento del colegio' }}
{{ $event['title'] }}
{{ $event['date'] }} {{ $event['time'] }}

{{ $event['description'] }}

{{ $english ? 'View event (student sign-in required)' : 'Ver evento (requiere iniciar sesión como estudiante)' }}:
{{ $event['url'] }}
