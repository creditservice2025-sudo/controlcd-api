<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <title>Movimientos de Pagos - Crédito {{ $credit->id ?? '' }}</title>
    <style>
        @page { margin: 18mm 10mm; }
        body { font-family: "DejaVu Sans", "Helvetica", sans-serif; font-size: 11px; color: #000; }
        .title { text-align: center; font-weight: 700; font-size: 16px; margin-bottom: 6px; }
        .header { width: 100%; margin-bottom: 6px; }
        .left, .right { display: inline-block; vertical-align: top; width: 49%; }

        
        .meta-row { margin-bottom: 4px; }
        .label { font-weight: 700; }
        hr.sep { border: 0; border-top: 2px solid #000; margin: 6px 0 8px 0; }

        table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 8px; }
        th, td { border: 1px solid #000; padding: 4px 6px; text-align: center; vertical-align: middle; }
        th { background: #eee; font-weight: 700; }
        .text-left { text-align: left; }
        .small { font-size: 9px; }
        .muted { color: #666; font-size: 9px; }
        .nowrap { white-space: nowrap; }
        .inner-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 4px; }
        .inner-table th, .inner-table td { border: 1px solid #999; padding: 3px 6px; text-align: center; }
        .inner-table th { background: #f5f5f5; }
    </style>
</head>
<body>
    <div class="title">MOVIMIENTOS DE PAGOS DE ABONOS</div>

    <div class="header">
        <div class="left">
            <div class="meta-row"><span class="label">CLIENTE:</span> {{ $client->name ?? 'N/A' }}</div>
            <div class="meta-row"><span class="label">CREDITO No.:</span> #00{{ $credit->id ?? 'N/A' }} - <span class="label">Ruta:</span> {{ optional($credit->seller->city)->name ?? 'N/A' }},
                {{ optional(optional($credit->seller->city)->country)->name ?? 'N/A' }}</div>
            <div class="meta-row"><span class="label">FORMA DE PAGO:</span> {{ $credit->payment_frequency ?? 'N/A' }}</div>
            <div class="meta-row"><span class="label">FECHA INICIAL:</span> {{ \Carbon\Carbon::parse($start_date ?? $credit->first_quota_date ?? now())->format('Y-m-d') }}</div>
            <div class="meta-row"><span class="label">FECHA DE GENERACION:</span>
                {{ \Carbon\Carbon::parse($report_date ?? now())->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}
            </div>
        </div>
        <div class="right">
            <div class="meta-row"><span class="label">CEDULA No.:</span> {{ $client->dni ?? 'N/A' }}</div>
            <div class="meta-row"><span class="label">SALDO INICIAL:</span> $ {{ number_format($capital ?? 0, 2) }}</div>
            <div class="meta-row"><span class="label">SALDO FINAL:</span> $ {{ number_format($total_credit_value ?? 0, 2) }}</div>
            <div class="meta-row"><span class="label">FECHA FINAL:</span> {{ \Carbon\Carbon::parse($end_date ?? now())->format('Y-m-d') }}</div>
            <div class="meta-row"><span class="label">CUOTAS PACTADAS:</span> {{ $number_installments ?? ($credit->number_installments ?? 'N/A') }}</div>
        </div>
    </div>


    <hr class="sep" />

    {{-- UNA SOLA TABLA: LISTADO DE PAGOS DEL CRÉDITO (con las cuotas que cubrió cada pago) --}}
    <div class="section-title" style="font-weight:700; margin-bottom:6px;">LISTADO DE PAGOS DEL CRÉDITO</div>

    @php
        // Variables base para cálculos
        $quotaAmount = $quota_amount ?? ($credit->number_installments ? round($total_credit_value / $credit->number_installments, 2) : 0);
        $totalCredit = $total_credit_value ?? ($total_credit_value ?? 0);
        $cumulativeApplied = 0.0;
        $totalInstallments = is_array($installments) ? count($installments) : (isset($credit->installments) ? $credit->installments->count() : 0);
        // calcular total canceladas (si existe info)
        $totalCanceled = 0;
        if (isset($installments) && is_array($installments)) {
            foreach ($installments as $it) {
                if (isset($it['status']) && in_array($it['status'], ['Cancelado','Anulado'])) $totalCanceled++;
            }
        } elseif (isset($credit->installments)) {
            $totalCanceled = $credit->installments->filter(function($it){ return $it->status === 'Cancelado' || $it->status === 'Anulado'; })->count();
        }
    @endphp

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>F. Pago</th>
                <th>Vr.Cuota</th>
                <th>Vr.Pagado</th>
                <th>Estado</th>
                <th>Cuo.Pagas</th>
                <th>Monto aplicado</th>
                <th>Saldo</th>
                <th>C.Pend.</th>
                <th>C.Canc.</th>
                <th>Atrasos</th>


                {{-- <th>F. Pago</th>
                <th>Vr.Cuota</th>
                <th>Cuo.Pagas</th>
                <th>Estado</th>
                <th>Vr.Pagado</th>
                <th>Acum.Pago</th>
                <th>Vr.Pend.</th> --}}
                
            </tr>
        </thead>
        <tbody>
            @if(!empty($payments_list) && count($payments_list) > 0)
                @foreach($payments_list as $idx => $p)
                    @php
                        // suma aplicada en este pago
                        $appliedSum = 0.0;
                        $appliedInstallmentsNums = [];
                        $maxDelay = 0;
                        if(!empty($p['applied_to'])) {
                            foreach($p['applied_to'] as $ap) {
                                $appliedSum += (float) ($ap['applied_amount'] ?? 0);
                                $appliedInstallmentsNums[] = $ap['quota_number'] ?? $ap['installment_id'];
                                $maxDelay = max($maxDelay, isset($ap['days_delay']) ? (int)$ap['days_delay'] : 0);
                            }
                        }
                        // actualizar acumulado
                        $cumulativeApplied += $appliedSum;
                        $balanceAfter = max(0, round($totalCredit - $cumulativeApplied, 2));
                        // cuotas pendientes estimadas (redondeo hacia arriba)
                        $pendingQuotas = $quotaAmount > 0 ? (int) ceil($balanceAfter / $quotaAmount) : 0;
                        // cuota lista de numeros
                        $appliedInstallmentsStr = count($appliedInstallmentsNums) ? implode(', ', $appliedInstallmentsNums) : '-';
                    @endphp
                    <tr>
                        <td>{{ $idx + 1 }}</td>
                        <td>{{ $p['created_at'] ?? 'N/A' }}</td>
                        <td>$ {{ $quotaAmount  }}</td>
                        <td>$ {{ number_format($p['amount'] ?? 0, 2) }}</td>
                        <td>{{ $p['status'] ?? 'N/A' }}</td>
                        <td>{{ count($p['applied_to'] ?? []) }}</td>
                        <td>$ {{ number_format($appliedSum, 2) }}</td>
                        <td>$ {{ number_format($balanceAfter, 2) }}</td>
                        <td>{{ $pendingQuotas }}</td>
                        <td>{{ $totalCanceled }}</td>
                        <td>{{ $maxDelay }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="12" class="text-left small">No hay pagos registrados para este crédito</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer-summary">
        <strong>Total Capital:</strong> $ {{ number_format($capital ?? 0, 2) }} &nbsp;&nbsp;
        <strong>Total Interés:</strong> $ {{ number_format($interest ?? 0, 2) }} &nbsp;&nbsp;
        <strong>Microseguro:</strong> $ {{ number_format($micro_insurance ?? 0, 2) }} &nbsp;&nbsp;
        <strong>Total Crédito:</strong> $ {{ number_format($total_credit_value ?? 0, 2) }}
    </div>
</body>
</html>