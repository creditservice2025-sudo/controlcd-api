<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nómina Semanal - {{ $payroll->seller->user->name }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 14px;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0056b3;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #0056b3;
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .info-column {
            display: table-cell;
            width: 50%;
        }
        .info-item {
            margin-bottom: 8px;
        }
        .info-label {
            font-weight: bold;
            color: #555;
            display: inline-block;
            width: 140px;
        }
        table.styled-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            font-size: 0.9em;
            font-family: sans-serif;
            min-width: 400px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);
        }
        table.styled-table thead tr {
            background-color: #0084ff;
            color: #ffffff;
            text-align: left;
        }
        table.styled-table th,
        table.styled-table td {
            padding: 12px 15px;
        }
        table.styled-table tbody tr {
            border-bottom: 1px solid #dddddd;
        }
        table.styled-table tbody tr:nth-of-type(even) {
            background-color: #f3f3f3;
        }
        table.styled-table tbody tr:last-of-type {
            border-bottom: 2px solid #0084ff;
        }
        .totals {
            width: 50%;
            float: right;
            margin-top: 20px;
        }
        .totals-row {
            padding: 8px 0;
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dashed #ccc;
        }
        .totals-label {
            font-weight: bold;
            float: left;
        }
        .totals-value {
            float: right;
        }
        .net-total {
            background-color: #e6f2ff;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            font-size: 18px;
            text-align: right;
            border: 2px solid #0056b3;
        }
        .net-total span {
            font-weight: bold;
            color: #0056b3;
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        .negative {
            color: #d9534f;
        }
        .currency {
            font-family: 'Courier New', Courier, monospace;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>DEUDAS Y ABONOS</h1>
        <p>Recibo de Nómina Semanal</p>
        <p>Periodo: {{ \Carbon\Carbon::parse($payroll->start_date)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($payroll->end_date)->format('d/m/Y') }}</p>
    </div>

    <div class="info-section">
        <div class="info-column">
            <div class="info-item">
                <span class="info-label">Vendedor:</span> {{ $payroll->seller->user->name }}
            </div>
            <div class="info-item">
                <span class="info-label">Documento:</span> {{ $payroll->seller->document ?? 'N/A' }}
            </div>
        </div>
        <div class="info-column">
            <div class="info-item">
                <span class="info-label">Recaudo Semanal:</span> <span class="currency">${{ number_format($payroll->total_collected, 2) }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Estado:</span> {{ $payroll->status == 'pending' ? 'PENDIENTE' : 'PAGADO' }}
            </div>
        </div>
    </div>

    <table class="styled-table">
        <thead>
            <tr>
                <th>Concepto</th>
                <th style="text-align: right;">Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Sueldo Base Fijo</td>
                <td style="text-align: right;" class="currency">${{ number_format($payroll->salary, 2) }}</td>
            </tr>
            <tr>
                <td>Comisión por Recaudo</td>
                <td style="text-align: right;" class="currency">${{ number_format($payroll->commission_collection, 2) }}</td>
            </tr>
            <tr>
                <td>Comisión por Utilidad</td>
                <td style="text-align: right;" class="currency">${{ number_format($payroll->commission_utility, 2) }}</td>
            </tr>
            <tr>
                <td>Comisión por Créditos Saldados</td>
                <td style="text-align: right;" class="currency">${{ number_format($payroll->commission_credits, 2) }}</td>
            </tr>
            <tr>
                <td>Viáticos</td>
                <td style="text-align: right;" class="currency">${{ number_format($payroll->allowance, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="clearfix">
        <div class="totals">
            <div class="totals-row clearfix">
                <span class="totals-label">Total Ingresos:</span>
                <span class="totals-value currency">${{ number_format($payroll->salary + $payroll->commission_collection + $payroll->commission_utility + $payroll->commission_credits + $payroll->allowance, 2) }}</span>
            </div>
            <div class="totals-row clearfix">
                <span class="totals-label">Descuento Ahorro:</span>
                <span class="totals-value negative currency">-${{ number_format($payroll->deductions_savings, 2) }}</span>
            </div>
            <div class="totals-row clearfix">
                <span class="totals-label">Descuento ARL:</span>
                <span class="totals-value negative currency">-${{ number_format($payroll->deductions_arl, 2) }}</span>
            </div>
            
            <div class="net-total">
                Neto a Pagar: <span>${{ number_format($payroll->net_total, 2) }}</span>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Generado automáticamente por el Sistema Deudas y Abonos.</p>
        <p>Fecha de emisión: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

</body>
</html>
