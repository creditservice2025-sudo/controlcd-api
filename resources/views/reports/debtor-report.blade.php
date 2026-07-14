<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Créditos Morosos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #222;
        }

        .header {
            text-align: center;
            margin-bottom: 12px;
        }

        .header h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
            color: #1f3b73;
        }

        .meta {
            font-size: 11px;
            color: #555;
            margin-bottom: 10px;
        }

        .meta strong {
            color: #222;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 5px 6px;
        }

        th {
            background-color: #1f3b73;
            color: #fff;
            font-size: 10px;
            text-transform: uppercase;
        }

        td {
            font-size: 10px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .due { color: #c0392b; font-weight: bold; }
        .paid { color: #1e8449; font-weight: bold; }

        tr:nth-child(even) td { background-color: #f7f9fc; }

        .summary {
            margin-top: 20px;
            padding: 8px 12px;
            background: #eef2f8;
            border: 1px solid #cdd7e6;
            border-left: 4px solid #1f3b73;
        }

        .summary-title {
            font-weight: bold;
            color: #1f3b73;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .summary-item {
            display: inline-block;
            margin-right: 18px;
            font-size: 11px;
            color: #333;
        }

        .level {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 9px;
            color: #fff;
        }

        .lvl-leve { background: #f6c453; color: #6b4e00; }
        .lvl-moderada { background: #f0a35e; }
        .lvl-grave { background: #e8795a; }
        .lvl-muygrave { background: #d9534f; }
        .lvl-critica { background: #b02a37; }
    </style>
</head>

<body>
    <div class="header">
        <h1>Lista de Créditos Morosos</h1>
    </div>

    <div class="meta">
        <strong>Vendedor:</strong> {{ $sellerName }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Créditos morosos:</strong> {{ $totalCredits }} &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Generado:</strong> {{ $reportDate }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:26px;">N°</th>
                <th style="text-align:left;">Cliente</th>
                <th>Crédito</th>
                <th>Cuotas Pend.</th>
                <th class="text-right">Monto Neto</th>
                <th>Tasa</th>
                <th class="text-right">Interés</th>
                <th class="text-right">Crédito + Interés</th>
                <th class="text-right">Monto Adeudado</th>
                <th class="text-right">Monto Pagado</th>
                <th>Días Mora</th>
                <th>Nivel Morosidad</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td>{{ $r['client_name'] }} <span style="color:#888;">(ID: {{ $r['client_code'] }})</span></td>
                    <td class="text-center">{{ $r['credit_code'] }}</td>
                    <td class="text-center">{{ $r['pending'] }}</td>
                    <td class="text-right">$ {{ $r['neto_fmt'] }}</td>
                    <td class="text-center">{{ $r['tasa_fmt'] }}</td>
                    <td class="text-right">$ {{ $r['interes_fmt'] }}</td>
                    <td class="text-right">$ {{ $r['total_amount_fmt'] }}</td>
                    <td class="text-right due">$ {{ $r['amount_due_fmt'] }}</td>
                    <td class="text-right paid">$ {{ $r['paid_fmt'] }}</td>
                    <td class="text-center">{{ $r['days'] }}</td>
                    <td class="text-center">
                        <span class="level lvl-{{ $r['level_class'] }}">{{ $r['level'] }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center" style="padding:16px;color:#888;">
                        Este vendedor no tiene créditos morosos.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (count($rows) > 0)
        {{-- Resumen del reporte (pie): panel claro, NO cabecera. --}}
        <div class="summary">
            <div class="summary-title">Resumen del reporte</div>
            <div class="summary-item">Créditos morosos: <b>{{ $totalCredits }}</b></div>
            <div class="summary-item">Monto Neto: <b>$ {{ $totalNetoFmt }}</b></div>
            <div class="summary-item">Interés: <b>$ {{ $totalInteresFmt }}</b></div>
            <div class="summary-item">Crédito + Interés: <b>$ {{ $totalCreditInterestFmt }}</b></div>
            <div class="summary-item">Monto Adeudado: <b class="due">$ {{ $totalAmountDueFmt }}</b></div>
            <div class="summary-item">Monto Pagado: <b class="paid">$ {{ $totalPaidFmt }}</b></div>
        </div>
    @endif
</body>

</html>
