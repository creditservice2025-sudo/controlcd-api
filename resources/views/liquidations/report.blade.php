<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Reporte de Cuadre Diario</title>
    <style>
        /* Horizontal: la tabla de pagos pasó de 8 a 12 columnas (valor del
           crédito, interés, estatus y fecha de la próxima cuota). En vertical
           los nombres de cliente se parten en tres renglones y las cifras se
           encabalgan. Las demás tablas del reporte son de 4 a 6 columnas y
           solo quedan más anchas. */
        @page {
            size: letter landscape;
            margin: 12mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        /* Rejilla liviana: solo líneas horizontales. La rejilla completa
           compite con los números y ensucia la lectura en tablas anchas. */
        th,
        td {
            border: none;
            border-bottom: 1px solid #e5e7eb;
            padding: 6px 7px;
            text-align: center;
        }

        thead th {
            background-color: #14477d;
            color: #ffffff;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: none;
            padding: 7px;
        }

        /* Repetir el encabezado cuando la tabla parte a la página siguiente:
           sin esto las filas del segundo pliego quedan sin títulos. */
        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
        }

        /* Solo la tabla de pagos, que es la que ganó columnas. */
        table.pagos th,
        table.pagos td {
            padding: 4px 4px;
            font-size: 9.5px;
        }

        .row-alt td {
            background-color: #f7f9fc;
        }

        tfoot th {
            background-color: #eef2f7;
            border-top: 2px solid #14477d;
            border-bottom: none;
            font-size: 10.5px;
            padding: 7px;
        }

        /* Fecha del pago, debajo del monto. */
        .sub {
            display: block;
            font-size: 8px;
            color: #6b7280;
            font-weight: normal;
        }

        .badge {
            display: inline-block;
            padding: 1px 6px;
            font-size: 8.5px;
            font-weight: bold;
        }

        .st-ok {
            background-color: #e3f3e8;
            color: #1c6b3a;
        }

        .st-live {
            background-color: #e4edf9;
            color: #14477d;
        }

        .st-other {
            background-color: #fdeaea;
            color: #9b2226;
        }

        /* ---- Encabezado: logotipo a la izquierda, título a la derecha ---- */
        .header {
            margin-bottom: 10px;
            border-bottom: 3px solid #14477d;
            padding-bottom: 8px;
        }

        .head-table,
        .head-table td {
            border: none;
            margin: 0;
            padding: 0;
        }

        .head-logo {
            width: 200px;
            text-align: left;
            vertical-align: middle;
        }

        .head-title {
            text-align: right;
            vertical-align: middle;
        }

        .doc-title {
            font-size: 17px;
            font-weight: bold;
            color: #14477d;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .doc-sub {
            font-size: 12px;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 2px;
        }

        /* ---- Barra de datos del cierre ---- */
        .meta {
            margin-bottom: 16px;
        }

        .meta td {
            border: none;
            background-color: #f3f6fa;
            border-bottom: 2px solid #dbe3ee;
            padding: 6px 10px;
            text-align: left;
            width: 33%;
        }

        .meta-k {
            display: block;
            font-size: 8px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .meta-v {
            display: block;
            font-size: 11.5px;
            font-weight: bold;
            color: #14477d;
        }

        /* ---- Resumen del cierre, en dos columnas ---- */
        .panel-title {
            font-size: 11.5px;
            font-weight: bold;
            color: #14477d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .resumen {
            margin-bottom: 26px;
        }

        .resumen td {
            border: none;
            border-bottom: 1px solid #eef1f5;
            padding: 5px 10px;
            font-size: 10.5px;
        }

        .res-k {
            text-align: left;
            color: #4b5563;
            width: 30%;
        }

        .res-v {
            text-align: right;
            font-weight: bold;
            color: #1f2937;
            width: 20%;
        }

        .res-hi {
            background-color: #eef2f7;
            color: #14477d;
            font-weight: bold;
            font-size: 11.5px;
        }

        /* ---- Firmas ---- */
        .firmas {
            margin-top: 34px;
            page-break-inside: avoid;
        }

        .firmas td {
            border: none;
            padding: 0;
            text-align: center;
            width: 40%;
        }

        .firma-sep {
            width: 20%;
        }

        .firma-linea {
            border-top: 1px solid #4b5563;
            margin: 0 12% 6px 12%;
        }

        .firma-label {
            font-size: 10px;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .pie-nota {
            margin-top: 26px;
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 8px;
            color: #9ca3af;
            letter-spacing: 0.3px;
        }

        h4 {
            background-color: #eef2f7;
            border-left: 4px solid #14477d;
            margin: 18px 0 8px 0;
            padding: 6px 10px;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .footer {
            margin-top: 24px;
            border-top: 3px solid #14477d;
            padding-top: 10px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-left {
            text-align: left;
        }

        .page-break {
            page-break-after: always;
        }

        h1 {
            margin: 4px 0;
            font-size: 16px;
            color: #14477d;
            letter-spacing: 0.3px;
        }

        h2 {
            margin: 3px 0;
            font-size: 12px;
            font-weight: normal;
            color: #374151;
        }

        h3 {
            margin: 3px 0;
            font-size: 11px;
            font-weight: bold;
            color: #6b7280;
            letter-spacing: 0.5px;
        }

        .summary-table {
            width: 50%;
            margin-top: 20px;
        }

        .logo {
            width: 185px;
        }

        .logo-placeholder {
            padding: 15px;
            background-color: #f0f0f0;
            border: 1px dashed #ccc;
            text-align: center;
            margin-bottom: 15px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="header">
        @php
            // El logotipo completo de Controll CD, no el isotipo suelto. Se
            // prefiere el PNG: dompdf lo rasteriza sin sorpresas, mientras que
            // su soporte de SVG es parcial y puede salir en blanco.
            $logoPath = null;
            $possiblePaths = [
                public_path('images/logo-email.png'),
                public_path('images/logo.svg'),
                public_path('images/favicon.svg'),
                base_path('public/images/logo-email.png'),
            ];

            foreach ($possiblePaths as $path) {
                if (file_exists($path) && !is_dir($path)) {
                    $logoPath = $path;
                    break;
                }
            }

            $rutaNombre = $report['seller'] ? strtoupper($report['seller']->city->name) : 'GENERAL';
            $cobrador = $report['user'] ? $report['user']->name : 'TODOS';

            // La fecha llegaba cruda desde la base ("2026-08-01 00:00:00"). La
            // hora siempre es 00:00:00 y no dice nada.
            try {
                $fechaCierre = \Carbon\Carbon::parse($report['report_date'])->format('d/m/Y');
            } catch (\Throwable $e) {
                $fechaCierre = $report['report_date'];
            }
        @endphp

        <table class="head-table">
            <tr>
                <td class="head-logo">
                    @if ($logoPath)
                        <img src="{{ $logoPath }}" class="logo" alt="Controll CD">
                    @else
                        <div class="logo-placeholder">CONTROLL CD</div>
                    @endif
                </td>
                <td class="head-title">
                    <div class="doc-title">Cierre aplicado del cuadre diario</div>
                    <div class="doc-sub">Listado de ruta {{ $rutaNombre }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td>
                <span class="meta-k">Ruta</span>
                <span class="meta-v">{{ $rutaNombre }}</span>
            </td>
            <td>
                <span class="meta-k">Cobrador encargado del cierre</span>
                <span class="meta-v">{{ $cobrador }}</span>
            </td>
            <td>
                <span class="meta-k">Fecha del cierre</span>
                <span class="meta-v">{{ $fechaCierre }}</span>
            </td>
        </tr>
    </table>

    @php
        $total_paid_today = 0;
        foreach ($report['report_data'] as $item) {
            $total_paid_today += $item['paid_today'];
        }
    @endphp

    <table class="pagos">
        <thead>
            <tr>
                <th>No.</th>
                <th>Cliente</th>
                <th>Crédito</th>
                <th>Frecuencia</th>
                <th>Valor Crédito</th>
                <th>Interés $ (%)</th>
                <th>Vr. Cuota</th>
                <th>Por Pagar</th>
                <th>Vr. Pago Hoy</th>
                <th>Estatus</th>
                <th>F. Pago Pend.</th>
                <th>Hora</th>
            </tr>
        </thead>
        <tbody>
            @if(count($report['report_data']) === 0)
                <tr>
                    <td colspan="12" class="text-center">No hay pagos para la fecha.</td>
                </tr>
            @else
                @foreach ($report['report_data'] as $item)
                    @php
                        $estatus = $item['status'] ?? '';
                        $estatusClass = $estatus === 'Liquidado'
                            ? 'st-ok'
                            : ($estatus === 'Vigente' ? 'st-live' : 'st-other');
                    @endphp
                    <tr class="{{ $loop->even ? 'row-alt' : '' }}">
                        <td>{{ $item['no'] }}</td>
                        <td class="text-left">{{ $item['client_name'] }}</td>
                        <td>#00{{ $item['credit_id'] }}</td>
                        <td>{{ $item['payment_frequency'] }}</td>
                        <td class="text-right">$ {{ number_format($item['capital'], 2) }}</td>
                        <td class="text-right">$ {{ number_format($item['interest'], 2) }} ({{ rtrim(rtrim(number_format($item['interest_rate'], 2), '0'), '.') }}%)</td>
                        <td class="text-right">$ {{ number_format($item['quota_amount'], 2) }}</td>
                        <td class="text-right">$ {{ number_format($item['remaining_amount'], 2) }}</td>
                        <td class="text-right">
                            $ {{ number_format($item['paid_today'], 2) }}
                            @if (!empty($item['payment_date']))
                                <span class="sub">{{ $item['payment_date'] }}</span>
                            @endif
                        </td>
                        <td><span class="badge {{ $estatusClass }}">{{ $estatus !== '' ? $estatus : 'N/A' }}</span></td>
                        <td>{{ $item['next_due_date'] ?? 'N/A' }}</td>
                        <td>{{ $item['payment_time'] ?? 'N/A' }}</td>
                    </tr>
                @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <th colspan="8">TOTAL DE PAGOS</th>
                <th class="text-right">$ {{ number_format($total_paid_today, 2) }}</th>
                <th colspan="3"></th>
            </tr>
        </tfoot>
    </table>

    <h4>LISTADO DE CRÉDITOS NUEVOS DENTRO DEL COBRO</h4>
    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Cliente</th>
                <th>Crédito</th>
                <th>F. Pago</th>
                <th>V.C + U</th>
            </tr>
        </thead>
        <tbody>
            @php
                $total_new_credits_value = 0;
            @endphp
            @if(count($report['new_credits'] ?? []) === 0)
                <tr>
                    <td colspan="5" class="text-center">No hay créditos nuevos para la fecha.</td>
                </tr>
            @else
                @foreach ($report['new_credits'] ?? [] as $index => $credit)
                    @php
                        $utilidad = $credit->credit_value * ($credit->total_interest / 100);
                        $total = $credit->credit_value + $utilidad;
                        $total_new_credits_value += $credit->credit_value + $utilidad;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left">{{ $credit->client->name }}</td>
                        <td>#00{{ $credit->id }}</td>
                        <td>{{ $credit->payment_frequency }}</td>
                        <td class="text-right">
                            ${{ number_format($credit->credit_value, 2) }} +
                            ${{ number_format($utilidad, 2) }} =
                            ${{ number_format($total, 2) }}
                        </td>
                    </tr>
                @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4">TOTAL CRÉDITOS NUEVOS:</th>
                <th class="text-right">$ {{ number_format($total_new_credits_value, 2) }}</th>
            </tr>
        </tfoot>
    </table>

    @php
        $totalMicroinsuranceNewCredits = 0;
        $creditsWithMicroinsurance = [];
        foreach ($report['new_credits'] ?? [] as $credit) {
            if ($credit->micro_insurance_percentage > 0) {
                $microinsuranceValue = ($credit->micro_insurance_percentage * $credit->credit_value) / 100;
                $totalMicroinsuranceNewCredits += $microinsuranceValue;
                $creditsWithMicroinsurance[] = $credit;
            }
        }
    @endphp

    @if (count($creditsWithMicroinsurance ?? []) > 0)
        @php
            $totalMicroinsurance = 0;
        @endphp

        <h4>LISTADO DE MICROSEGUROS EN CRÉDITOS NUEVOS</h4>
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Cliente</th>
                    <th>Crédito</th>
                    <th>V.C</th>
                    <th>% Microseguro</th>
                    <th>Vr. Microseguro</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($creditsWithMicroinsurance as $index => $credit)
                    @php
                        $microinsuranceValue = ($credit->micro_insurance_percentage * $credit->credit_value) / 100;
                        $totalMicroinsurance += $microinsuranceValue;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left">{{ $credit->client->name }}</td>
                        <td>#00{{ $credit->id }}</td>
                        <td class="text-right">
                            ${{ number_format($credit->credit_value, 2) }}
                        </td>
                        <td class="text-right">{{ number_format($credit->micro_insurance_percentage, 2) }}%</td>
                        <td class="text-right">${{ number_format($microinsuranceValue, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5">TOTAL MICROSEGUROS</th>
                    <th class="text-right">${{ number_format($totalMicroinsurance, 2) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif

    <h4>LISTADO DE GASTOS DENTRO DEL COBRO</h4>
    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Descripción</th>
                <th>Categoría</th>
                <th>Vr. Gasto</th>
            </tr>
        </thead>
        <tbody>
            @php $total_expenses_value = 0; @endphp
            @if(!isset($report['expenses']) || count($report['expenses'] ?? []) === 0)
                <tr>
                    <td colspan="4" class="text-center">No hay gastos para la fecha.</td>
                </tr>
            @else
                @foreach ($report['expenses'] ?? [] as $index => $expense)
                    @php $total_expenses_value += $expense->value; @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left">{{ $expense->description }}</td>
                        <td class="text-left">{{ $expense->category->name ?? 'N/A' }}</td>
                        <td class="text-right">$ {{ number_format($expense->value, 2) }}</td>
                    </tr>
                @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">TOTAL DE GASTOS</th>
                <th class="text-right">$ {{ number_format($total_expenses_value, 2) }}</th>
            </tr>
        </tfoot>
    </table>

    <h4>LISTADO DE INGRESOS DENTRO DEL COBRO</h4>
    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Descripción</th>
                <th>Vr. Ingreso</th>
            </tr>
        </thead>
        <tbody>
            @php $total_incomes_value = 0; @endphp
            @if(!isset($report['incomes']) || count($report['incomes'] ?? []) === 0)
                <tr>
                    <td colspan="3" class="text-center">No hay ingresos para la fecha.</td>
                </tr>
            @else
                @foreach ($report['incomes'] ?? [] as $index => $income)
                    @php $total_incomes_value += $income->value; @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left">{{ $income->description }}</td>
                        <td class="text-right">$ {{ number_format($income->value, 2) }}</td>
                    </tr>
                @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">TOTAL DE INGRESOS</th>
                <th class="text-right">$ {{ number_format($total_incomes_value, 2) }}</th>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        @php
            // El desglose se arma como lista y después se acomoda en dos
            // columnas: en horizontal, una sola columna dejaba media hoja
            // vacía. Cada ítem es [etiqueta, valor, ¿es dinero?, ¿destacado?].
            $resumen = [
                ['Total recibos en ruta', $report['total_credits'] ?? 0, false, false],
                ['Recibos con pagos', $report['with_payment'] ?? 0, false, false],
                ['Recibos sin pagos', $report['without_payment'] ?? 0, false, false],
                ['Total recaudado', $report['total_collected'] ?? 0, true, true],
                ['Recaudo microseguro', $totalMicroinsuranceNewCredits, true, false],
            ];

            if (isset($report['total_incomes'])) {
                $resumen[] = ['Total ingresos', $report['total_incomes'], true, false];
            }
            if (isset($report['total_expenses'])) {
                $resumen[] = ['Total gastos', $report['total_expenses'], true, false];
            }
            if (count($report['new_credits'] ?? []) > 0) {
                $resumen[] = ['No. créditos nuevos', count($report['new_credits']), false, false];
            }
        @endphp

        <div class="panel-title">Resumen del cierre</div>
        <table class="resumen">
            @foreach (array_chunk($resumen, 2) as $fila)
                <tr>
                    @foreach ($fila as $celda)
                        <td class="res-k {{ $celda[3] ? 'res-hi' : '' }}">{{ $celda[0] }}</td>
                        <td class="res-v {{ $celda[3] ? 'res-hi' : '' }}">
                            {{ $celda[2] ? '$ ' . number_format($celda[1], 2) : $celda[1] }}
                        </td>
                    @endforeach
                    @if (count($fila) === 1)
                        <td class="res-k"></td>
                        <td class="res-v"></td>
                    @endif
                </tr>
            @endforeach
        </table>

        <table class="firmas">
            <tr>
                <td>
                    <div class="firma-linea"></div>
                    <div class="firma-label">Firma recaudador</div>
                </td>
                <td class="firma-sep"></td>
                <td>
                    <div class="firma-linea"></div>
                    <div class="firma-label">Firma cobrador</div>
                </td>
            </tr>
        </table>

        <div class="pie-nota">
            Generado por Controll CD &middot; Ruta {{ $rutaNombre }} &middot; Cierre del {{ $fechaCierre }}
        </div>
    </div>
</body>

</html>
