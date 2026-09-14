{{-- PDF del resumen general. Sale de los mismos datos que la pantalla. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 16px 28px 16px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1d2939; }

        .encabezado { border-bottom: 2px solid #2b69e8; padding-bottom: 6px; margin-bottom: 10px; }
        .marca { color: #2b69e8; font-size: 13px; font-weight: bold; }
        .titulo { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .sub { color: #667085; font-size: 8px; }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #2b69e8; color: #fff; font-size: 7.5px; font-weight: bold;
            padding: 5px 4px; text-align: right; border: 1px solid #2b69e8;
        }
        th.izq, td.izq { text-align: left; }
        th.centro, td.centro { text-align: center; }
        td { padding: 4px; text-align: right; border: 1px solid #e4e9f2; }
        tbody tr:nth-child(even) td { background: #f8fafc; }

        tfoot td {
            background: #f4f7fb; font-weight: bold;
            border-top: 2px solid #d9e2ef;
        }

        .leyenda { margin-top: 8px; color: #667085; font-size: 7px; line-height: 1.4; }

        /* Referencia de la escala de recaudo. Impreso no hay pantalla al lado
           para deducir qué significa cada color, así que viaja con el reporte. */
        .escala { margin-bottom: 8px; font-size: 7.5px; }
        .escala-titulo { color: #667085; margin-right: 3px; }
        .escala-item {
            display: inline-block; padding: 2px 6px; border-radius: 7px;
            font-weight: bold; margin-right: 3px;
        }
        .pie { position: fixed; bottom: -18px; left: 0; right: 0; color: #98a2b3; font-size: 7px; }
    </style>
</head>
<body>
    <div class="encabezado">
        <div class="marca">Controll CD</div>
        <div class="titulo">{{ $title }}</div>
        <div class="sub">{{ $subtitle }} &middot; Generado el {{ $generated_at }}</div>
    </div>

    @if (count($tone_legend))
        <div class="escala">
            <span class="escala-titulo">Recaudo del período:</span>
            @foreach ($tone_legend as $tramo)
                <span class="escala-item"
                      style="background: #{{ $tramo['fondo'] }}; color: #{{ $tramo['texto'] }};">{{ $tramo['etiqueta'] }}</span>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($columns as $i => $columna)
                    <th class="{{ $i === 0 ? 'izq' : ($i === 1 ? 'centro' : '') }}">{{ $columna }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $indiceFila => $fila)
                @php($tono = $row_tones[$indiceFila] ?? null)
                <tr>
                    @foreach ($fila as $i => $celda)
                        {{-- La banda cubre Vendedor, Moneda y Total Recaudado, igual
                             que en pantalla. De la cuarta columna en adelante hay
                             datos que no dependen del recaudo; teñirlos sugeriría
                             que sí. El estilo va inline porque es el único que
                             gana sobre el rayado de filas pares. --}}
                        <td class="{{ $i === 0 ? 'izq' : ($i === 1 ? 'centro' : '') }}"
                            @if ($tono && $i <= 2) style="background: #{{ $tone_colors[$tono]['fondo'] }}; color: #{{ $tone_colors[$tono]['texto'] }}; font-weight: bold;" @endif>
                            {{ in_array($i, $money_columns, true) ? number_format((float) $celda, 2, ',', '.') : $celda }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        @if (count($totals))
            <tfoot>
                @foreach ($totals as $fila)
                    <tr>
                        @foreach ($fila as $i => $celda)
                            <td class="{{ $i === 0 ? 'izq' : ($i === 1 ? 'centro' : '') }}">
                                {{ in_array($i, $money_columns, true) ? number_format((float) $celda, 2, ',', '.') : $celda }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tfoot>
        @endif
    </table>

    <div class="leyenda">
        <strong>Clientes Nuevos:</strong> es su primer crédito.
        <strong>Liquidó y Tomó Otro:</strong> ya tenía historia y no le quedaba ningún crédito abierto.
        <strong>Crédito Adicional:</strong> se le activó otro sin liquidar el anterior.
        Se cuentan clientes (no créditos) por el día del crédito, no por la liquidación.
        <br>
        Los totales van por moneda: sumar entre monedas distintas daría un número sin significado.
    </div>

    <div class="pie">Controll CD &middot; {{ $subtitle }}</div>
</body>
</html>
