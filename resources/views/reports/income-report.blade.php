<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Reporte de Ingresos</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #222; }
        .header { text-align: center; margin-bottom: 12px; }
        .header h1 { font-size: 18px; margin: 0 0 4px 0; color: #1f3b73; }
        .meta { font-size: 11px; color: #555; margin-bottom: 10px; }
        .meta strong { color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 5px 6px; }
        th { background-color: #1f3b73; color: #fff; font-size: 10px; text-transform: uppercase; }
        td { font-size: 10px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .val { color: #1e7e34; font-weight: bold; }
        tr.even td { background-color: #f7f9fc; }
        td { overflow: hidden; word-wrap: break-word; }
        .summary { margin-top: 20px; padding: 8px 12px; background: #eef2f8; border: 1px solid #cdd7e6; border-left: 4px solid #1f3b73; }
        .summary-title { font-weight: bold; color: #1f3b73; font-size: 12px; margin-bottom: 4px; }
        .summary-item { display: inline-block; margin-right: 18px; font-size: 11px; color: #333; }
    </style>
</head>

<body>
    <div class="header">
        <h1>Reporte de Ingresos</h1>
    </div>

    <div class="meta">
        <strong>Vendedor / Ruta:</strong> {{ $sellerName }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Rango:</strong> {{ $rangeLabel }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Ingresos:</strong> {{ $totalCount }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Generado:</strong> {{ $reportDate }}
    </div>

    {{-- Varias tablas chicas en vez de una gigante: dompdf maqueta una tabla
         larga en memoria entera (1.700 filas => ~1 GB y >30 s). --}}
    @foreach (count($rows) ? array_chunk($rows, 40, true) : [[]] as $chunk)
    <table>
        <thead>
            <tr>
                <th style="width:4%;">N°</th>
                <th style="width:12%;">Fecha</th>
                <th style="width:18%;text-align:left;">Vendedor</th>
                <th style="width:18%;text-align:left;">Realizado por</th>
                <th style="width:34%;text-align:left;">Descripción</th>
                <th style="width:14%;" class="text-right">Valor</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($chunk as $idx => $r)
                <tr class="{{ $idx % 2 ? 'even' : '' }}">
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td class="text-center">{{ $r['fecha'] }}</td>
                    <td>{{ $r['vendedor'] }}</td>
                    <td>{{ $r['realizado_por'] }}</td>
                    <td>{{ $r['descripcion'] }}</td>
                    <td class="text-right val">$ {{ $r['valor_fmt'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding:16px;color:#888;">
                        No hay ingresos para los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @endforeach

    @if (count($rows) > 0)
        <div class="summary">
            <div class="summary-title">Resumen del reporte</div>
            <div class="summary-item">Cantidad de ingresos: <b>{{ $totalCount }}</b></div>
            <div class="summary-item">Total: <b class="val">$ {{ $totalFmt }}</b></div>
        </div>
    @endif
</body>

</html>
