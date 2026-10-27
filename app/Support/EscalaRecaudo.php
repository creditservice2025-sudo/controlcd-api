<?php

namespace App\Support;

/**
 * Escala de recaudo por vendedor: el mismo criterio con el que la pantalla
 * pinta las filas, para que el Excel y el PDF no terminen diciendo otra cosa.
 *
 * Los cortes son INCLUSIVOS hacia arriba: 10.500 es rojo y 10.500,01 ya es
 * amarillo.
 *
 * Es el espejo de ESCALA_RECAUDO en
 * controlcd-app/src/pages/liquidations/ReportsPage.vue. Son dos lenguajes, así
 * que la constante no se puede compartir: si se mueve un corte, hay que moverlo
 * en los dos lados. Acá queda anotado para que no se descubra tarde.
 *
 * OJO: los montos son absolutos, sin convertir moneda. La ruta que los usa
 * liquida en PEN; una fila en otra moneda se mide contra la misma vara.
 */
class EscalaRecaudo
{
    /**
     * PHP_FLOAT_MAX y no INF como último techo: INF es válido en una constante
     * pero se vuelve un problema en cuanto alguien serializa esto a JSON.
     */
    public const TRAMOS = [
        ['hasta' => 10500.0, 'tono' => 'rojo', 'etiqueta' => 'hasta 10.500'],
        ['hasta' => 11500.0, 'tono' => 'amarillo', 'etiqueta' => '10.501 a 11.500'],
        ['hasta' => 13000.0, 'tono' => 'azul', 'etiqueta' => '11.501 a 13.000'],
        ['hasta' => PHP_FLOAT_MAX, 'tono' => 'verde', 'etiqueta' => 'más de 13.000'],
    ];

    /**
     * Texto y fondo de cada tono, en hex SIN '#'. Los mismos valores que el
     * CSS de la pantalla; el Excel les antepone 'FF' para el canal alfa.
     *
     * El amarillo es ámbar oscuro a propósito: el amarillo vivo sobre fondo
     * claro no alcanza contraste para leerse, ni en pantalla ni impreso.
     */
    public const COLORES = [
        'rojo' => ['texto' => 'C62828', 'fondo' => 'FFE3E6'],
        'amarillo' => ['texto' => 'B26A00', 'fondo' => 'FFF2CC'],
        'azul' => ['texto' => '1565C0', 'fondo' => 'DDECFD'],
        'verde' => ['texto' => '2E7D32', 'fondo' => 'DCF3DE'],
    ];

    /** Los cuatro tramos en orden (la clave es la posición, no el color). */
    public const TONOS = ['rojo', 'amarillo', 'azul', 'verde'];

    /**
     * Paleta de una meta de recaudo: texto y fondo de cada tramo, en hex SIN
     * '#'. Sin colores propios devuelve COLORES tal cual; con colores propios,
     * el texto es el color elegido y el fondo un tinte suave de ese mismo color
     * (88 % hacia el blanco), como los de la paleta original.
     *
     * @param  array<string, string>|null  $colores  {"rojo": "#AABBCC", ...}
     * @return array<string, array{texto: string, fondo: string}>
     */
    public static function paleta(?array $colores): array
    {
        $out = [];
        foreach (self::TONOS as $tono) {
            $hex = self::normalizarHex($colores[$tono] ?? null);
            $out[$tono] = $hex
                ? ['texto' => $hex, 'fondo' => self::tinte($hex, 0.88)]
                : self::COLORES[$tono];
        }
        return $out;
    }

    /** "#c62828" / "C62828" → "C62828"; cualquier otra cosa → null. */
    public static function normalizarHex(?string $hex): ?string
    {
        $hex = strtoupper(ltrim(trim((string) $hex), '#'));
        return preg_match('/^[0-9A-F]{6}$/', $hex) ? $hex : null;
    }

    /** Mezcla el color con blanco: $cuanto = 0 el mismo color, 1 blanco. */
    private static function tinte(string $hex, float $cuanto): string
    {
        $out = '';
        foreach (str_split($hex, 2) as $canal) {
            $v = hexdec($canal);
            $out .= str_pad(dechex((int) round($v + (255 - $v) * $cuanto)), 2, '0', STR_PAD_LEFT);
        }
        return strtoupper($out);
    }

    /** El primer tramo que contiene al monto. */
    public static function tono($monto): string
    {
        $n = (float) $monto;

        foreach (self::TRAMOS as $tramo) {
            if ($n <= $tramo['hasta']) {
                return $tramo['tono'];
            }
        }

        return 'verde';
    }

    public static function texto(string $tono): string
    {
        return self::COLORES[$tono]['texto'] ?? '1D2939';
    }

    public static function fondo(string $tono): string
    {
        return self::COLORES[$tono]['fondo'] ?? 'FFFFFF';
    }

    /**
     * Los tramos con su color resuelto, para dibujar la referencia dentro del
     * reporte. Un reporte con colores y sin referencia es una adivinanza: quien
     * lo recibe impreso no tiene la pantalla al lado para deducir la escala.
     */
    public static function leyenda(): array
    {
        return array_map(fn ($tramo) => [
            'tono' => $tramo['tono'],
            'etiqueta' => $tramo['etiqueta'],
            'texto' => self::texto($tramo['tono']),
            'fondo' => self::fondo($tramo['tono']),
        ], self::TRAMOS);
    }
}
