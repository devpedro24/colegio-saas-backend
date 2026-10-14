<!doctype html>
<html lang="{{ $english ? 'en' : 'es' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f7f2ee;font-family:Arial,sans-serif;color:#142343">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #e9e5f3;border-radius:24px;overflow:hidden">
<tr><td style="padding:30px;background:#6626ff;color:#fff;border-radius:24px 24px 0 0">
<p style="margin:0 0 10px;font-size:12px;letter-spacing:1px">{{ $english ? 'SCHOOL CALENDAR' : 'CALENDARIO ESCOLAR' }}</p>
<h1 style="margin:0;font-size:24px">{{ $event['school'] }}</h1></td></tr>
<tr><td style="padding:32px">
<span style="display:inline-block;background:#eeeaff;color:#5533b6;border-radius:20px;padding:8px 12px;font-size:12px">{{ $english ? 'New event' : 'Nuevo evento' }}</span>
<h2 style="font-size:26px;line-height:1.3;margin:24px 0 16px">{{ $event['title'] }}</h2>
<p style="background:#f5f3fc;border-radius:14px;padding:16px;line-height:1.7">{{ $english ? 'Date' : 'Fecha' }}: {{ $event['date'] }}@if($event['time']) · {{ substr($event['time'], 0, 5) }}@endif</p>
<div style="font-size:15px;line-height:1.75;white-space:pre-line;overflow-wrap:anywhere">{{ $event['description'] }}</div>
<p style="margin:30px 0"><a href="{{ $event['url'] }}" style="display:inline-block;border-radius:30px;background:#6633db;color:#fff;text-decoration:none;font-weight:bold;padding:16px 25px">{{ $english ? 'View event' : 'Ver evento' }}</a></p>
<p style="color:#61708f;font-size:12px;line-height:1.6">{{ $english ? 'Sign in with your student account to see the event and its attachments.' : 'Ingresa con tu cuenta de estudiante para consultar el evento y sus adjuntos.' }}</p>
<p style="font-size:12px;overflow-wrap:anywhere"><a href="{{ $event['url'] }}" style="color:#6633db">{{ $event['url'] }}</a></p>
</td></tr><tr><td style="padding:24px 32px;border-top:1px solid #ece6fa;background:#faf8ff;border-radius:0 0 24px 24px;font-size:12px;color:#61708f;line-height:1.6">{{ $english ? 'You received this notice because the event is addressed to your student group or school. This is an automated message.' : 'Recibiste este aviso porque el evento está dirigido a tu grupo o colegio. Este es un mensaje automático.' }}</td></tr>
</table></td></tr></table></body></html>
