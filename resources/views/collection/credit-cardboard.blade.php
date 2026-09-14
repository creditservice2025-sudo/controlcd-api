{{--
    Cartón digital del crédito (PDF).

    Reemplaza al cartón de papel del cobrador. Ojo: este producto es de crédito
    ABIERTO (interés mensual, capital flexible), así que NO lleva la grilla de N
    cuotas del cartón clásico — no existe ese N. En su lugar va el libro de
    movimientos con saldo de capital corriente.

    Los datos llegan de CollectionCreditService::buildCardboardData(), el mismo
    payload que consume la pantalla y la imagen de WhatsApp.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cartón de crédito #{{ $credit['id'] }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1f2933;
            margin: 0;
        }
        .sheet { border: 1.5px solid #0f172a; border-radius: 6px; overflow: hidden; }

        .head { background: #0f172a; color: #fff; padding: 10px 12px; }
        .head-title { font-size: 15px; font-weight: bold; letter-spacing: 0.4px; }
        .head-sub { font-size: 9px; color: #cbd5e1; margin-top: 2px; }
        .stamp {
            float: right; border: 1.5px solid #fff; border-radius: 4px;
            padding: 3px 10px; font-size: 11px; font-weight: bold; letter-spacing: 1px;
        }

        .band { padding: 8px 12px; border-bottom: 1px solid #dfe6ea; }
        .band-title {
            font-size: 8px; font-weight: bold; letter-spacing: 1.2px;
            color: #64748b; text-transform: uppercase; margin-bottom: 4px;
        }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        .label { color: #64748b; font-size: 9px; }
        .value { font-weight: bold; font-size: 11px; }

        .kpis { width: 100%; border-collapse: collapse; margin-top: 2px; }
        .kpis td {
            width: 25%; text-align: center; padding: 7px 4px;
            border-right: 1px solid #e2e8f0;
        }
        .kpis td:last-child { border-right: 0; }
        .kpi-label { font-size: 7.5px; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; }
        .kpi-value { font-size: 16px; font-weight: bold; margin-top: 2px; }
        .kpi-strong { color: #b45309; }

        .mov { width: 100%; border-collapse: collapse; margin-top: 3px; }
        .mov th {
            background: #f1f5f9; font-size: 8px; text-transform: uppercase;
            letter-spacing: 0.5px; color: #475569; padding: 5px 6px;
            border-bottom: 1px solid #cbd5e1; text-align: right;
        }
        .mov th.l, .mov td.l { text-align: left; }
        .mov td { padding: 5px 6px; border-bottom: 1px solid #eef2f5; text-align: right; }
        .mov .concept { font-weight: bold; }
        .mov .detail { color: #64748b; font-size: 8px; }
        .mov .bal { font-weight: bold; }
        .neg { color: #047857; }

        .badge {
            display: inline-block; padding: 1px 6px; border-radius: 3px;
            font-size: 8px; font-weight: bold; letter-spacing: 0.5px;
        }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-partial { background: #fef3c7; color: #92400e; }
        .badge-due { background: #e2e8f0; color: #475569; }
        .badge-next { background: #e0f2fe; color: #075985; }

        .foot { padding: 10px 12px; }
        .sign { border-top: 1px solid #94a3b8; margin-top: 26px; padding-top: 3px; font-size: 8px; color: #64748b; }
        .legal { font-size: 7.5px; color: #94a3b8; margin-top: 8px; line-height: 1.35; }
    </style>
</head>
<body>
<div class="sheet">

    <div class="head">
        <div class="stamp">{{ strtoupper($credit['status'] === 'active' ? 'Activo' : $credit['status']) }}</div>
        <div class="head-title">CARTÓN DE CRÉDITO N° {{ $credit['id'] }}</div>
        <div class="head-sub">
            {{ $company['name'] }}
            @if($credit['route_name']) &middot; Ruta {{ $credit['route_name'] }} @endif
        </div>
    </div>

    <div class="band">
        <div class="band-title">Titular</div>
        <table>
            <tr>
                <td style="width: 55%">
                    <div class="value" style="font-size: 13px">{{ $client['name'] }}</div>
                    <div class="label">Documento: {{ $client['dni'] ?: '—' }}</div>
                    <div class="label">Teléfono: {{ $client['phone'] ?: '—' }}</div>
                </td>
                <td>
                    <div class="label">Dirección</div>
                    <div style="font-size: 10px">{{ $client['address'] ?: '—' }}</div>
                    @if($client['reference'])
                        <div class="label" style="margin-top: 3px">Referencia: {{ $client['reference'] }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="band">
        <div class="band-title">Condiciones</div>
        <table>
            <tr>
                <td style="width: 25%">
                    <div class="label">Capital desembolsado</div>
                    <div class="value">{{ $money($credit['original_amount']) }}</div>
                </td>
                <td style="width: 25%">
                    <div class="label">Fecha de desembolso</div>
                    <div class="value">{{ $fecha($credit['business_date']) }}</div>
                </td>
                <td style="width: 25%">
                    <div class="label">Tasa mensual</div>
                    <div class="value">{{ rtrim(rtrim(number_format($credit['interest_rate'], 2, ',', '.'), '0'), ',') }}%</div>
                </td>
                <td style="width: 25%">
                    <div class="label">Cuota de interés vigente</div>
                    <div class="value">{{ $money($credit['current_period_interest']) }}</div>
                </td>
            </tr>
        </table>
        <div class="label" style="margin-top: 5px">
            Modalidad: crédito abierto — se paga el interés mensual y el capital se abona
            libremente. No hay un número fijo de cuotas.
        </div>
        @if($credit['next_period_interest'] != $credit['current_period_interest'])
            {{-- Al agregar capital la cuota vigente no se recalcula: el cliente
                 debe ver por qué la próxima cuota cambia de valor. --}}
            <div class="label" style="margin-top: 3px">
                A partir de la próxima cuota el interés será de
                <strong>{{ $money($credit['next_period_interest']) }}</strong>,
                por el capital agregado durante este período.
            </div>
        @endif
    </div>

    <div class="band" style="padding: 0">
        <table class="kpis">
            <tr>
                <td>
                    <div class="kpi-label">Capital vivo</div>
                    <div class="kpi-value">{{ $money($summary['live_principal']) }}</div>
                </td>
                <td>
                    <div class="kpi-label">Interés del período</div>
                    <div class="kpi-value">{{ $money($summary['pending_interest']) }}</div>
                </td>
                <td>
                    <div class="kpi-label">Total para liquidar</div>
                    <div class="kpi-value kpi-strong">{{ $money($summary['payoff_total']) }}</div>
                </td>
                <td>
                    <div class="kpi-label">Abonado histórico</div>
                    <div class="kpi-value">{{ $money($summary['total_paid']) }}</div>
                </td>
            </tr>
        </table>
        @if($credit['next_due_date'])
            <div style="text-align:center; padding: 0 0 7px; font-size: 9px; color:#475569">
                Próximo vencimiento del interés: <strong>{{ $fecha($credit['next_due_date']) }}</strong>
            </div>
        @endif
    </div>

    @php
        // Base del avance: abonado + pendiente. En un crédito abierto no hay un
        // total de contrato fijo (cada mes se devenga interés nuevo), así que se
        // mide contra lo que el crédito lleva costado a hoy.
        $progressBase = $summary['total_paid'] + $summary['payoff_total'];
        $pct = fn ($v) => $progressBase > 0 ? round($v / $progressBase * 100, 2) : 0;
    @endphp
    @if($progressBase > 0)
        <div class="band">
            <div class="band-title">Avance del pago</div>
            <table style="width:100%; margin-bottom: 4px">
                <tr>
                    <td class="label">Abonado {{ $money($summary['total_paid']) }} de {{ $money($progressBase) }}</td>
                    <td style="text-align:right"><span class="value" style="font-size:13px">{{ number_format($pct($summary['total_paid']), 1, ',', '.') }}%</span></td>
                </tr>
            </table>

            {{-- Barra apilada con tablas: DomPDF no soporta flexbox. --}}
            <table style="width:100%; height:12px; border-collapse:collapse; border:1px solid #cbd5e1; background:#e2e8f0">
                <tr>
                    @if($pct($summary['principal_paid']) > 0)
                        <td style="width:{{ $pct($summary['principal_paid']) }}%; background:#0f766e"></td>
                    @endif
                    @if($pct($summary['interest_paid']) > 0)
                        <td style="width:{{ $pct($summary['interest_paid']) }}%; background:#f59e0b"></td>
                    @endif
                    <td style="width:{{ max(0, 100 - $pct($summary['principal_paid']) - $pct($summary['interest_paid'])) }}%"></td>
                </tr>
            </table>

            <table style="width:100%; margin-top:6px">
                <tr>
                    <td class="label" style="width:34%">
                        <span class="badge" style="background:#0f766e; color:#fff">&nbsp;</span>
                        Capital devuelto <strong>{{ $money($summary['principal_paid']) }}</strong>
                    </td>
                    <td class="label" style="width:33%">
                        <span class="badge" style="background:#f59e0b; color:#fff">&nbsp;</span>
                        Interés pagado <strong>{{ $money($summary['interest_paid']) }}</strong>
                    </td>
                    <td class="label" style="width:33%">
                        <span class="badge" style="background:#cbd5e1; color:#cbd5e1">&nbsp;</span>
                        Por pagar <strong>{{ $money($summary['payoff_total']) }}</strong>
                    </td>
                </tr>
            </table>
            <div class="label" style="margin-top:4px">
                Por pagar = capital {{ $money($summary['live_principal']) }}
                + interés {{ $money($summary['pending_interest']) }}
            </div>
        </div>
    @endif

    <div class="band">
        <div class="band-title">Cuotas de interés</div>
        <table class="mov">
            <thead>
            <tr>
                <th class="l" style="width: 12%">Cuota</th>
                <th class="l" style="width: 20%">Vence</th>
                <th style="width: 20%">Interés</th>
                <th style="width: 20%">Abonado</th>
                <th class="l" style="width: 28%">Estado</th>
            </tr>
            </thead>
            <tbody>
            @foreach($installments as $i)
                <tr>
                    <td class="l">#{{ $i['number'] }}</td>
                    <td class="l">{{ $fecha($i['due_date']) }}</td>
                    <td>{{ $money($i['amount']) }}</td>
                    <td>{{ $money($i['paid_amount']) }}</td>
                    <td class="l">
                        @if($i['is_paid'])
                            <span class="badge badge-paid">PAGADA</span>
                        @elseif($i['paid_amount'] > 0)
                            <span class="badge badge-partial">ABONO PARCIAL</span>
                            <span class="detail">falta {{ $money($i['pending']) }}</span>
                        @else
                            <span class="badge badge-due">PENDIENTE</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            @if($next_installment)
                <tr>
                    <td class="l">#{{ $next_installment['number'] }}</td>
                    <td class="l">{{ $fecha($next_installment['due_date']) }}</td>
                    <td>{{ $money($next_installment['amount']) }}</td>
                    <td>—</td>
                    <td class="l"><span class="badge badge-next">PRÓXIMA (estimada)</span></td>
                </tr>
            @endif
            </tbody>
        </table>
        @if(count($installments) === 0)
            <div class="label" style="padding: 8px 0">Sin cuotas generadas.</div>
        @endif
    </div>

    <div class="band">
        <div class="band-title">Movimientos</div>
        <table class="mov">
            <thead>
            <tr>
                <th class="l" style="width: 15%">Fecha</th>
                <th class="l" style="width: 37%">Concepto</th>
                <th style="width: 16%">Capital</th>
                <th style="width: 16%">Interés</th>
                <th style="width: 16%">Saldo capital</th>
            </tr>
            </thead>
            <tbody>
            @foreach($movements as $m)
                <tr>
                    <td class="l">{{ $fecha($m['date']) }}</td>
                    <td class="l">
                        <div class="concept">{{ $m['concept'] }}</div>
                        @if($m['detail'])<div class="detail">{{ $m['detail'] }}</div>@endif
                    </td>
                    <td class="{{ $m['principal'] < 0 ? 'neg' : '' }}">
                        {{ $m['principal'] != 0 ? $money($m['principal']) : '—' }}
                    </td>
                    <td>{{ $m['interest'] != 0 ? $money($m['interest']) : '—' }}</td>
                    <td class="bal">{{ $money($m['balance']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if(count($movements) === 0)
            <div class="label" style="padding: 8px 0">Sin movimientos registrados.</div>
        @endif
    </div>

    <div class="foot">
        <table>
            <tr>
                <td style="width: 48%"><div class="sign">Firma del cliente</div></td>
                <td style="width: 4%"></td>
                <td style="width: 48%"><div class="sign">Firma del cobrador</div></td>
            </tr>
        </table>
        <div class="legal">
            Documento informativo emitido el {{ $issued_at }} ({{ $timezone }}).
            Refleja el estado del crédito al momento de la emisión; los abonos posteriores
            no están incluidos. Conserve este cartón para el control de sus pagos.
        </div>
    </div>

</div>
</body>
</html>
