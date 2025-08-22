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
        th, td { 
            border: 1px solid #ddd; 
            padding: 6px; 
            text-align: left; 
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
        .page-break {
            page-break-after: always;
        }
        h1, h2, h3 {
            margin: 5px 0;
        }
        .summary-table {
            width: 50%;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>CIERRE APLICADO DEL CUADRE DIARIO DE LISTADO DE RUTA {{ $seller ? strtoupper($seller->name) : 'GENERAL' }}</h1>
        <h2>Cobrador Encargado de este Cierre: {{ $seller ? $seller->name : 'TODOS' }}</h2>
        <h3>FECHA DEL CIERRE: {{ $report_date }}</h3>
    </div>

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
            @foreach($report_data as $item)
            <tr>
                <td>{{ $item['no'] }}</td>
                <td>{{ $item['client_name'] }}</td>
                <td>#00{{ $item['credit_id'] }}</td>
                <td>{{ $item['payment_frequency'] }}</td>
                <td class="text-right">$ {{ number_format($item['quota_amount'], 2) }}</td>
                <td class="text-right">$ {{ number_format($item['remaining_amount'], 2) }}</td>
                <td class="text-right">$ {{ number_format($item['paid_today'], 2) }}</td>
                <td>{{ $item['payment_time'] ?? 'N/A' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if(count($new_credits) > 0)
    <h4>LISTADO DE CRÉDITOS NUEVOS DENTRO DEL COBRO</h4>
    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Cliente</th>
                <th>Crédito</th>
                <th>F. Pago</th>
                <th>Vr. Capital</th>
            </tr>
        </thead>
        <tbody>
            @foreach($new_credits as $index => $credit)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $credit->client->name }}</td>
                <td>#00{{ $credit->id }}</td>
                <td>{{ $credit->payment_frequency }}</td>
                <td class="text-right">$ {{ number_format($credit->credit_value, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <p><strong>TOTAL CRÉDITOS NUEVOS:</strong> $ {{ number_format($total_new_credits, 2) }}</p>
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
            @if(count($new_credits) > 0)
            <tr>
                <td><strong>No. CRÉDITOS NUEVOS:</strong></td>
                <td class="text-right">{{ count($new_credits) }}</td>
            </tr>
            @endif
        </table>
        
        <div style="margin-top: 30px;">
            <p>_________________________</p>
            <p>FIRMA RECAUDADOR</p>
            <p>_________________________</p>
            <p>FIRMA COBRADOR</p>
        </div>
    </div>
</body>
</html>