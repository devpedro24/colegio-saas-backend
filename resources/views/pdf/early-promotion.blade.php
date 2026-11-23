<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de promoción anticipada {{ $number }}</title>
    <style>
        @page { margin: 23mm 24mm; }
        body { font: 11pt/1.5 "DejaVu Sans", sans-serif; color: #202d37; }
        header { border-top: 4pt solid #276560; padding-top: 12pt; margin-bottom: 22pt; }
        .school { font-weight: bold; font-size: 13pt; color: #234643; }
        .ref { font-size: 9pt; color: #4c6066; margin-top: 4pt; }
        h1 { font-size: 24pt; line-height: 1.22; margin: 0 0 19pt; color: #193c3b; }
        p { margin: 0 0 18pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 19pt 0; }
        th, td { padding: 9pt 12pt; text-align: left; vertical-align: top; border-bottom: 1px solid #d5dfdd; }
        th { width: 34%; background: #f1f6f5; font-weight: normal; color: #314d4a; }
        td, .school, .signer, strong { overflow-wrap: break-word; word-wrap: break-word; }
        .signature { margin-top: 24pt; page-break-inside: avoid; }
        .line { width: 55%; border-top: 1px solid #6d8583; margin-bottom: 6pt; }
        .signer { font-weight: bold; }
        .caption { font-size: 9pt; color: #4c6066; }
    </style>
</head>
<body>
<header>
    <div class="school">{{ $school }}</div>
    <div class="ref">Registro escolar &nbsp;·&nbsp; {{ $number }} &nbsp;·&nbsp; Versión 1</div>
</header>
<h1>Constancia de promoción anticipada</h1>
<p>El Consejo Directivo aprobó la promoción anticipada de <strong>{{ $student }}</strong> durante el primer periodo del año lectivo {{ $year }}.</p>
<table>
    <tr><th>Grado y grupo de origen</th><td>{{ $origin }}</td></tr>
    <tr><th>Grado y grupo de destino</th><td><strong>{{ $destination }}</strong></td></tr>
    <tr><th>Fecha de promoción</th><td>{{ $decisionDate }}</td></tr>
    <tr><th>Acta del Consejo Directivo</th><td>{{ $minutes }}</td></tr>
</table>
<p>Fecha de expedición &nbsp;·&nbsp; {{ $issueDate }}</p>
<div class="signature">
    <div class="line"></div>
    <div class="signer">{{ $signer }}</div>
    <div class="caption">Firmante de la constancia</div>
</div>
@isset($verification)
    @include('pdf.certificate-verification-block')
@endisset
</body>
</html>
