<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><title>Verificación de {{ $number }}</title>
<style>
    @page { margin: 23mm 24mm } body { font: 11pt/1.5 'DejaVu Sans', sans-serif; color:#202d37 }
    header {border-top:4pt solid #276560;padding:12pt 0 25pt;color:#234643;font-weight:bold}
    h1 {font-size:23pt;line-height:1.2;color:#193c3b}
</style></head><body>
<header>{{ $school }}</header>
<h1>Verificación de la constancia<br>{{ $number }}</h1>
<p>Este anexo permite consultar el registro de emisión de la constancia de promoción anticipada que lo acompaña. Compara el colegio, el consecutivo y la fecha de expedición con la primera página.</p>
@include('pdf.certificate-verification-block')
</body></html>
