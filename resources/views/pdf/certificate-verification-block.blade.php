<table style="width:100%; table-layout:auto; border-collapse:collapse; margin:18pt 0 0; border-top:1pt solid #276560; page-break-inside:avoid">
    <tr>
        <td style="width:30mm; padding:8pt 2pt 0 0; border:0; vertical-align:middle">
            <img src="{{ $verification['qr'] }}" width="104" height="104" alt="Código QR de verificación">
        </td>
        <td style="padding:8pt 0 0 5pt; border:0; vertical-align:middle; font:9pt/1.45 'DejaVu Sans',sans-serif; color:#324c49">
            <strong>Verificar la emisión</strong><br>
            Escanea el QR o ingresa este código en el portal del colegio.<br>
            <strong style="font:11pt 'DejaVu Sans Mono',monospace">{{ $verification['codigo'] }}</strong><br>
            <a href="{{ $verification['url'] }}" style="font-size:8pt;color:#245958;word-wrap:break-word">{{ $verification['url'] }}</a>
        </td>
    </tr>
</table>
