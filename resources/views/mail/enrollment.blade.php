<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f1ee;color:#12213c;font-family:Arial,Helvetica,sans-serif;">
  <div style="display:none;font-size:1px;color:#f5f1ee;max-height:0;overflow:hidden;opacity:0;">{{ $subject }} — {{ $schoolName }}</div>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f1ee;">
    <tr><td align="center" style="padding:32px 16px 48px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e8e3ef;border-radius:22px;overflow:hidden;">
        <tr><td style="background:#6827f7;padding:24px 32px;color:#ffffff;">
          <div style="font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;opacity:.85;">Ingreso estudiantil</div>
          <div style="font-size:21px;font-weight:700;line-height:1.35;margin-top:6px;">{{ $schoolName }}</div>
        </td></tr>
        <tr><td style="padding:36px 32px 34px;">
          <div style="display:inline-block;background:#e7fff0;color:#057638;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;">Matrículas por enlace</div>
          <h1 style="font-size:26px;line-height:1.25;letter-spacing:-.5px;margin:22px 0 18px;color:#10203f;">{{ $subject }}</h1>
          @if($pin)
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:4px 0 24px;border:1px solid #d6c4ff;border-radius:16px;background:#f7f2ff;">
            <tr><td align="center" style="padding:22px 12px;">
              <p style="margin:0 0 10px;color:#5a2bc0;font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;">Tu PIN de verificación</p>
              <div style="font-family:Arial,Helvetica,sans-serif;color:#401690;font-size:38px;line-height:1.15;letter-spacing:12px;font-weight:800;">{{ $pin }}</div>
              <p style="margin:10px 0 0;color:#635779;font-size:13px;">Válido durante 15 minutos · No lo compartas</p>
            </td></tr>
          </table>
          <p style="font-size:15px;line-height:1.55;margin:0 0 18px;color:#34415d;">Abre tu enlace privado y escribe este PIN para continuar la inscripción.</p>
          @endif
          @foreach($lines as $line)
            @if(trim($line) === '')
              <div style="height:10px;line-height:10px;">&nbsp;</div>
            @else
              <p style="font-size:16px;line-height:1.65;margin:0 0 10px;color:#34415d;overflow-wrap:anywhere;">{{ $line }}</p>
            @endif
          @endforeach
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 12px;"><tr><td bgcolor="#10c45c" style="background:#10c45c;border-radius:999px;">
            <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 26px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;border-radius:999px;">{{ $actionLabel }}</a>
          </td></tr></table>
          <p style="font-size:13px;line-height:1.6;color:#65708a;margin:12px 0 0;">Si el botón no funciona, copia este enlace en tu navegador:<br><a href="{{ $actionUrl }}" style="color:#6827f7;overflow-wrap:anywhere;">{{ $actionUrl }}</a></p>
        </td></tr>
        <tr><td style="border-top:1px solid #e8e3ef;padding:22px 32px;background:#fbf9ff;">
          <p style="margin:0;font-size:12px;line-height:1.6;color:#65708a;">Mensaje automático de {{ $schoolName }}. Si no iniciaste esta solicitud, puedes ignorarlo. Nunca compartas tu PIN ni tu contraseña temporal.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
