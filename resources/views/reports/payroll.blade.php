<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Nómina semanal</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #222; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 17px; margin: 0 0 3px 0; color: #1f3b73; }
        .meta { font-size: 10px; color: #555; margin-bottom: 8px; }
        .meta strong { color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 4px 5px; overflow: hidden; word-wrap: break-word; }
        th { background-color: #1f3b73; color: #fff; font-size: 8.5px; text-transform: uppercase; }
        td { font-size: 9px; }
        .r { text-align: right; }
        .c { text-align: center; }
        tr.even td { background-color: #f7f9fc; }
        .net { font-weight: bold; color: #1e7e34; }
        .neg { color: #c0392b; }
        .cur { margin-top: 14px; font-size: 12px; font-weight: bold; color: #1f3b73; }
        .summary { margin-top: 6px; padding: 6px 10px; background: #eef2f8; border: 1px solid #cdd7e6; border-left: 4px solid #1f3b73; }
        .summary span { display: inline-block; margin-right: 16px; font-size: 10px; }
        .note { margin-top: 10px; font-size: 9px; color: #777; }
    </style>
</head>

<body>
    @php
        $fmt = fn ($v, $cur) => number_format((float) $v, in_array($cur, ['COP', 'CLP', 'PYG']) ? 0 : 2, ',', '.');
        $groups = collect($p['items'])->groupBy('currency');
        $totals = collect($p['totals'])->keyBy('currency');
    @endphp

    <div class="header">
        <h1>Nómina semanal de cobradores</h1>
    </div>

    <div class="meta">
        <strong>Empresa:</strong> {{ $p['company_name'] ?? '—' }} &nbsp;|&nbsp;
        <strong>Semana:</strong> {{ $p['week_start'] }} al {{ $p['week_end'] }} &nbsp;|&nbsp;
        <strong>Estado:</strong> {{ $p['status_label'] }} &nbsp;|&nbsp;
        <strong>Generó:</strong> {{ $p['created_by'] ?? '—' }}
        @if (!empty($p['approved_by']))
            &nbsp;|&nbsp; <strong>Aprobó:</strong> {{ $p['approved_by'] }}
        @endif
    </div>

    @forelse ($groups as $currency => $items)
        <div class="cur">Moneda: {{ $currency }}</div>
        {{-- Varias tablas chicas: dompdf maqueta una tabla larga entera en memoria. --}}
        @foreach ($items->values()->chunk(35) as $chunk)
            <table>
                <thead>
                    <tr>
                        <th style="width:3%;">N°</th>
                        <th style="width:15%;text-align:left;">Vendedor</th>
                        <th style="width:15%;text-align:left;">Regla</th>
                        <th style="width:9%;" class="r">Recaudo</th>
                        <th style="width:8%;" class="r">Créd. nuevos</th>
                        <th style="width:8%;" class="r">Com. recaudo</th>
                        <th style="width:7%;" class="r">Com. coloc.</th>
                        <th style="width:7%;" class="r">Fijo + viát.</th>
                        <th style="width:6%;" class="r">Bonos</th>
                        <th style="width:7%;" class="r">Dsctos.</th>
                        <th style="width:9%;" class="r">Neto</th>
                        <th style="width:6%;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chunk as $idx => $i)
                        <tr class="{{ $idx % 2 ? 'even' : '' }}">
                            <td class="c">{{ $idx + 1 }}</td>
                            <td>{{ $i['seller_name'] }}</td>
                            <td>{{ $i['rule_label'] }}</td>
                            <td class="r">{{ $fmt($i['collection_base'], $currency) }}</td>
                            <td class="r">{{ $fmt($i['placement_capital'], $currency) }}</td>
                            <td class="r">{{ $fmt($i['collection_commission'] + $i['tier_bonus'], $currency) }}</td>
                            <td class="r">{{ $fmt($i['placement_commission'], $currency) }}</td>
                            <td class="r">{{ $fmt($i['fixed_salary'] + $i['allowance'], $currency) }}</td>
                            <td class="r">{{ $fmt($i['bonuses_total'], $currency) }}</td>
                            <td class="r neg">{{ $fmt($i['deductions'], $currency) }}</td>
                            <td class="r {{ $i['net'] < 0 ? 'neg' : 'net' }}">{{ $fmt($i['net'], $currency) }}</td>
                            <td class="c">{{ $i['status_label'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        @if ($totals->has($currency))
            @php $t = $totals[$currency]; @endphp
            <div class="summary">
                <span>Vendedores: <b>{{ $t['sellers'] }}</b></span>
                <span>Recaudo: <b>{{ $currency }} {{ $fmt($t['collection_base'], $currency) }}</b></span>
                <span>Total a pagar: <b>{{ $currency }} {{ $fmt($t['net'], $currency) }}</b></span>
                <span>Pagado: <b>{{ $currency }} {{ $fmt($t['paid'], $currency) }}</b></span>
                <span>Pendiente: <b>{{ $currency }} {{ $fmt($t['pending'], $currency) }}</b></span>
            </div>
        @endif
    @empty
        <p class="c" style="padding:16px;color:#888;">La nómina no tiene vendedores.</p>
    @endforelse

    <div class="note">
        El pago de cada línea se registra como gasto en la caja del cobrador el día en que se paga.
        Los totales no se suman entre monedas distintas.
    </div>
</body>

</html>
