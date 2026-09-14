<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Saldo acumulado por período</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #1f2937; }
        .brand { color: #0d9488; font-weight: bold; font-size: 13px; letter-spacing: .5px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 8px; margin-bottom: 10px; }
        .header h1 { font-size: 18px; margin: 2px 0 2px 0; color: #111827; }
        .header .sub { font-size: 11px; color: #6b7280; }
        .meta { font-size: 11px; color: #555; margin: 8px 0 4px; }
        .meta strong { color: #222; }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d7dce3; padding: 6px 8px; }
        th { background-color: #0d9488; color: #fff; font-size: 10px; text-transform: uppercase; }
        td { font-size: 10px; }
        .num { text-align: right; }
        tr:nth-child(even) td { background-color: #f6fbfa; }
        .range { color: #9ca3af; font-size: 9px; }
        .neg { color: #c0392b; font-weight: bold; }
        .pos { color: #0d9488; font-weight: bold; }
        .row-open td { background: #f0fdfa; font-style: italic; color: #6b7280; }
        tfoot td { border-top: 2px solid #0d9488; font-weight: bold; background: #f6fbfa; }
        .footer { margin-top: 14px; font-size: 9px; color: #9ca3af; text-align: center; }
        .transf { color: #2563eb; font-weight: bold; }
        .det-wrap td { padding: 0; border: 1px solid #d7dce3; background: #fbfcfe; }
        .det { width: 100%; border-collapse: collapse; }
        .det td { border: none; border-bottom: 1px dashed #e5e9ef; padding: 4px 8px; font-size: 9px; }
        .det tr:last-child td { border-bottom: none; }
        .det .d-date { color: #6b7280; white-space: nowrap; }
        .det .d-author { color: #6b7280; }
        .det-empty { padding: 5px 8px; font-size: 9px; color: #9ca3af; font-style: italic; }
    </style>
</head>

<body>
    <div class="header">
        <div class="brand">CONTROL · DEUDA &amp; ABONO</div>
        <h1>{{ $companyName }}</h1>
        <div class="sub">Saldo acumulado por período &middot; vista {{ $granularityLabel }}</div>
    </div>

    <div class="meta">
        <strong>Rango:</strong> {{ $rangeLabel }} &nbsp;|&nbsp;
        <strong>Moneda:</strong> {{ $currency }}
        @if($countryCode) &nbsp;|&nbsp; <strong>País:</strong> {{ $countryCode }} @endif
        &nbsp;|&nbsp; <strong>Generado:</strong> {{ $reportDate }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="text-align:left;">Período</th>
                <th class="num">Ingresos</th>
                <th class="num">Gastos</th>
                <th class="num">Neto</th>
                <th class="num">Saldo acumulado</th>
            </tr>
        </thead>
        <tbody>
            @if($hasOpening)
                <tr class="row-open">
                    <td colspan="4">Saldo de apertura (arrastre)</td>
                    <td class="num">{{ $openingBalance }}</td>
                </tr>
            @endif
            @forelse ($rows as $r)
                <tr>
                    <td>
                        {{ $r['label'] }}
                        @if($r['range'])<div class="range">{{ $r['range'] }}</div>@endif
                    </td>
                    <td class="num pos">{{ $r['ingresos'] }}</td>
                    <td class="num neg">{{ $r['gastos'] }}</td>
                    <td class="num {{ $r['neto_neg'] ? 'neg' : 'pos' }}">{{ $r['neto'] }}</td>
                    <td class="num {{ $r['acum_neg'] ? 'neg' : 'pos' }}">{{ $r['acumulado'] }}</td>
                </tr>
                <tr class="det-wrap">
                    <td colspan="5">
                        @if(count($r['movements']))
                            <table class="det">
                                @foreach($r['movements'] as $m)
                                    <tr>
                                        <td class="d-date" style="width:110px;">{{ $m['date'] }}</td>
                                        <td style="width:150px;">{{ $m['type'] }}</td>
                                        <td>{{ $m['desc'] }}</td>
                                        <td class="d-author" style="width:120px;">{{ $m['author'] }}</td>
                                        <td class="num {{ $m['cls'] }}" style="width:110px;">{{ $m['amount'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @else
                            <div class="det-empty">Sin movimientos manuales en este período.</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center; color:#9ca3af;">Sin movimientos en el rango.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total del rango</td>
                <td class="num">{{ $totalIngresos }}</td>
                <td class="num">{{ $totalGastos }}</td>
                <td class="num">{{ $totalNeto }}</td>
                <td class="num {{ $closingNeg ? 'neg' : 'pos' }}">{{ $closingBalance }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        Generado por Control CD · Deuda &amp; Abono — {{ $reportDate }}
    </div>
</body>

</html>
