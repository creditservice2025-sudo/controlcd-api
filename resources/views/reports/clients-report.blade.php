<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Reporte de Clientes</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; color: #222; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 17px; margin: 0 0 4px 0; color: #1f3b73; }
        .meta { font-size: 10px; color: #555; margin-bottom: 8px; }
        .meta strong { color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 4px 5px; }
        th { background-color: #1f3b73; color: #fff; font-size: 9px; text-transform: uppercase; }
        td { font-size: 9px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .green { color: #1e7e34; font-weight: bold; }
        .orange { color: #b9770e; font-weight: bold; }
        tr:nth-child(even) td { background-color: #f7f9fc; }
        .summary { margin-top: 16px; padding: 8px 12px; background: #eef2f8; border: 1px solid #cdd7e6; border-left: 4px solid #1f3b73; }
        .summary-title { font-weight: bold; color: #1f3b73; font-size: 12px; margin-bottom: 4px; }
        .summary-item { display: inline-block; margin-right: 18px; font-size: 11px; color: #333; }
    </style>
</head>

<body>
    <div class="header">
        <h1>Reporte de Clientes</h1>
    </div>

    <div class="meta">
        <strong>Filtro:</strong> {{ $tabLabel }}
        @if ($sellerName) &nbsp;|&nbsp; <strong>Vendedor / Ruta:</strong> {{ $sellerName }} @endif
        @if ($locationLabel) &nbsp;|&nbsp; <strong>Ubicación:</strong> {{ $locationLabel }} @endif
        &nbsp;|&nbsp; <strong>Clientes:</strong> {{ $totalCount }}
        &nbsp;|&nbsp; <strong>Generado:</strong> {{ $reportDate }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:22px;">N°</th>
                <th>Fecha</th>
                <th style="text-align:left;">Cliente</th>
                <th style="text-align:left;">Vendedor (ruta)</th>
                <th>Empresa</th>
                <th style="text-align:left;">Créditos</th>
                <th class="text-right">Valor total</th>
                <th class="text-right">Recaudado</th>
                <th class="text-right">Por cobrar</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td class="text-center">{{ $r['fecha'] }}</td>
                    <td>{{ $r['cliente'] }}</td>
                    <td>{{ $r['vendedor'] }}<br><span style="color:#888;">{{ $r['ruta'] }}</span></td>
                    <td class="text-center">{{ $r['empresa'] }}</td>
                    <td>{{ $r['creditos'] }}</td>
                    <td class="text-right">$ {{ $r['valor_total_fmt'] }}</td>
                    <td class="text-right green">$ {{ $r['recaudado_fmt'] }}</td>
                    <td class="text-right orange">$ {{ $r['por_cobrar_fmt'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center" style="padding:16px;color:#888;">
                        No hay clientes para los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (count($rows) > 0)
        <div class="summary">
            <div class="summary-title">Resumen del reporte</div>
            <div class="summary-item">Clientes: <b>{{ $totalCount }}</b></div>
            <div class="summary-item">Valor total: <b>$ {{ $totValorFmt }}</b></div>
            <div class="summary-item">Recaudado: <b class="green">$ {{ $totRecaudadoFmt }}</b></div>
            <div class="summary-item">Por cobrar: <b class="orange">$ {{ $totPorCobrarFmt }}</b></div>
        </div>
    @endif
</body>

</html>
