<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Reporte de Gastos</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #222; }
        .header { text-align: center; margin-bottom: 12px; }
        .header h1 { font-size: 18px; margin: 0 0 4px 0; color: #1f3b73; }
        .meta { font-size: 11px; color: #555; margin-bottom: 10px; }
        .meta strong { color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 5px 6px; }
        th { background-color: #1f3b73; color: #fff; font-size: 10px; text-transform: uppercase; }
        td { font-size: 10px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .val { color: #c0392b; font-weight: bold; }
        tr:nth-child(even) td { background-color: #f7f9fc; }
        .estado { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9px; background: #e8f5e9; color: #1e7e34; }
        .summary { margin-top: 20px; padding: 8px 12px; background: #eef2f8; border: 1px solid #cdd7e6; border-left: 4px solid #1f3b73; }
        .summary-title { font-weight: bold; color: #1f3b73; font-size: 12px; margin-bottom: 4px; }
        .summary-item { display: inline-block; margin-right: 18px; font-size: 11px; color: #333; }
    </style>
</head>

<body>
    <div class="header">
        <h1>Reporte de Gastos</h1>
    </div>

    <div class="meta">
        <strong>Vendedor / Ruta:</strong> {{ $sellerName }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Rango:</strong> {{ $rangeLabel }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Gastos:</strong> {{ $totalCount }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Generado:</strong> {{ $reportDate }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:26px;">N°</th>
                <th>Fecha</th>
                <th style="text-align:left;">Realizado por</th>
                <th>Categoría</th>
                <th style="text-align:left;">Descripción</th>
                <th>Estado</th>
                <th class="text-right">Valor</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td class="text-center">{{ $r['fecha'] }}</td>
                    <td>{{ $r['realizado_por'] }}</td>
                    <td class="text-center">{{ $r['categoria'] }}</td>
                    <td>{{ $r['descripcion'] }}</td>
                    <td class="text-center"><span class="estado">{{ $r['estado'] }}</span></td>
                    <td class="text-right val">$ {{ $r['valor_fmt'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding:16px;color:#888;">
                        No hay gastos para este vendedor en el rango seleccionado.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (count($rows) > 0)
        <div class="summary">
            <div class="summary-title">Resumen del reporte</div>
            <div class="summary-item">Cantidad de gastos: <b>{{ $totalCount }}</b></div>
            <div class="summary-item">Total: <b class="val">$ {{ $totalFmt }}</b></div>
        </div>
    @endif
</body>

</html>
