<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Reporte de Cuadre Diario</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
        }

        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .footer {
            margin-top: 30px;
            border-top: 1px solid #000;
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

        h1,
        h2,
        h3 {
            margin: 5px 0;
        }

        .summary-table {
            width: 50%;
            margin-top: 20px;
        }

        .logo {
            max-width: 250px;
            margin-bottom: 10px;
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
            $logoPath = null;
            $possiblePaths = [
                public_path('images/favicon.svg'),
                public_path('storage/images/favicon.svg'),
                storage_path('app/public/images/favicon.svg'),
                base_path('public/images/favicon.svg'),
            ];

            foreach ($possiblePaths as $path) {
                if (file_exists($path) && !is_dir($path)) {
                    $logoPath = $path;
                    break;
                }
            }
        @endphp

        @if ($logoPath)
            <img src="{{ $logoPath }}" class="logo" alt="Logo">
        @else
            <div class="logo-placeholder">
                LOGO DE LA EMPRESA
            </div>
        @endif

        <h1>CIERRE APLICADO DEL CUADRE DIARIO DE LISTADO DE RUTA
            {{ $seller ? strtoupper($seller->city->name) : 'GENERAL' }}</h1>
        <h2>Cobrador Encargado de este Cierre: {{ $user ? $user->name : 'TODOS' }}</h2>
        <h3>FECHA DEL CIERRE: {{ $report_date }}</h3>
    </div>

    @php
        $total_paid_today = 0;
        foreach ($report_data as $item) {
            $total_paid_today += $item['paid_today'];
        }
    @endphp

    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Cliente</th>
                <th>Crédito</th>
                <th>Frecuencia</th>
                <th>Vr. Cuota</th>
                <th>Saldo Actual</th>
                <th>Vr. Pago Hoy</th>
                <th>Hora</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report_data as $item)
                <tr>
                    <td>{{ $item['no'] }}</td>
                    <td class="text-left">{{ $item['client_name'] }}</td>
                    <td>#00{{ $item['credit_id'] }}</td>
                    <td>{{ $item['payment_frequency'] }}</td>
                    <td class="text-right">$ {{ number_format($item['quota_amount'], 2) }}</td>
                    <td class="text-right">$ {{ number_format($item['remaining_amount'], 2) }}</td>
                    <td class="text-right">$ {{ number_format($item['paid_today'], 2) }}</td>
                    <td>{{ $item['payment_time'] ?? 'N/A' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="6">TOTAL DE PAGOS</th>
                <th class="text-right">$ {{ number_format($total_paid_today, 2) }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    @if (count($new_credits) > 0)
        @php
            $total_new_credits_value = 0;
            foreach ($new_credits as $credit) {
                $total_new_credits_value += $credit->credit_value;
            }
        @endphp

        <h4 class="text-center">LISTADO DE CRÉDITOS NUEVOS DENTRO DEL COBRO</h4>
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
                @foreach ($new_credits as $index => $credit)
                    @php
                        $utilidad = $credit->credit_value * ($credit->total_interest / 100);
                        $total = $credit->credit_value + $utilidad;
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
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4">TOTAL CRÉDITOS NUEVOS:</th>
                    <th class="text-right">$ {{ number_format($total_new_credits_value, 2) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif


    @php
        $totalMicroinsuranceNewCredits = 0;
        $creditsWithMicroinsurance = [];
        foreach ($new_credits as $credit) {
            if ($credit->micro_insurance_percentage > 0) {
                $microinsuranceValue = ($credit->micro_insurance_percentage * $credit->credit_value) / 100;
                $totalMicroinsuranceNewCredits += $microinsuranceValue;
                $creditsWithMicroinsurance[] = $credit;
            }
        }
    @endphp

    @if (count($creditsWithMicroinsurance) > 0)
        @php
            $totalMicroinsurance = 0;
        @endphp

        <h4 class="text-center">LISTADO DE MICROSEGUROS EN CRÉDITOS NUEVOS</h4>
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

    @if (isset($expenses) && count($expenses) > 0)
        @php
            $total_expenses_value = 0;
            foreach ($expenses as $expense) {
                $total_expenses_value += $expense->value;
            }
        @endphp

        <h4 class="text-center">LISTADO DE GASTOS DENTRO DEL COBRO</h4>
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
                @foreach ($expenses as $index => $expense)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left">{{ $expense->description }}</td>
                        <td class="text-left">{{ $expense->category->name ?? 'N/A' }}</td>
                        <td class="text-right">$ {{ number_format($expense->value, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">TOTAL DE GASTOS</th>
                    <th class="text-right">$ {{ number_format($total_expenses_value, 2) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif

    @if (isset($incomes) && count($incomes) > 0)
        @php
            $total_incomes_value = 0;
            foreach ($incomes as $income) {
                $total_incomes_value += $income->value;
            }
        @endphp

        <h4 class="text-center">LISTADO DE INGRESOS DENTRO DEL COBRO</h4>
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Descripción</th>
                    <th>Vr. Ingreso</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($incomes as $index => $income)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left">{{ $income->description }}</td>
                        <td class="text-right">$ {{ number_format($income->value, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">TOTAL DE INGRESOS</th>
                    <th class="text-right">$ {{ number_format($total_incomes_value, 2) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="footer">
        <table class="summary-table">
            <tr>
                <td><strong>TOTAL RECIBOS EN RUTA:</strong></td>
                <td class="text-right">{{ $total_credits }}</td>
            </tr>
            <tr>
                <td><strong>RECIBOS CON PAGOS:</strong></td>
                <td class="text-right">{{ $with_payment }}</td>
            </tr>
            <tr>
                <td><strong>RECIBOS SIN PAGOS:</strong></td>
                <td class="text-right">{{ $without_payment }}</td>
            </tr>
            <tr>
                <td><strong>TOTAL RECAUDADO:</strong></td>
                <td class="text-right">$ {{ number_format($total_collected, 2) }}</td>
            </tr>

            <tr>
                <td><strong>RECAUDO MICROSEGURO:</strong></td>
                <td class="text-right">$ {{ number_format($totalMicroinsuranceNewCredits, 2) }}</td>
            </tr>
            @if (isset($total_incomes))
                <tr>
                    <td><strong>TOTAL INGRESOS:</strong></td>
                    <td class="text-right">$ {{ number_format($total_incomes, 2) }}</td>
                </tr>
            @endif
            @if (isset($total_expenses))
                <tr>
                    <td><strong>TOTAL GASTOS:</strong></td>
                    <td class="text-right">$ {{ number_format($total_expenses, 2) }}</td>
                </tr>
            @endif

            @if (count($new_credits) > 0)
                <tr>
                    <td><strong>No. CRÉDITOS NUEVOS:</strong></td>
                    <td class="text-right">{{ count($new_credits) }}</td>
                </tr>
            @endif
        </table>

        <div style="margin-top: 30px; text-align: center;">
            <p>_________________________</p>
            <p>FIRMA RECAUDADOR</p>
            <p>_________________________</p>
            <p>FIRMA COBRADOR</p>
        </div>
    </div>
</body>

</html>
