<?php

namespace App\Support;

/**
 * Montos con el formato de la moneda de cada país: símbolo, decimales y
 * separadores estilo LatAm (punto de miles, coma decimal).
 *
 * Es el espejo de formatCurrencyFixed / getCurrencySymbol en
 * controlcd-app/src/utils/currency.ts, para que el Excel y el PDF muestren los
 * montos igual que la pantalla. Si se agrega una moneda allá, va también acá.
 */
class FormatoMoneda
{
    public const SIMBOLOS = [
        'PEN' => 'S/', 'MXN' => '$', 'COP' => '$', 'USD' => '$', 'ARS' => '$',
        'BRL' => 'R$', 'CLP' => '$', 'BOB' => 'Bs', 'VES' => 'Bs.', 'CRC' => '₡',
        'GTQ' => 'Q', 'HNL' => 'L', 'NIO' => 'C$', 'PYG' => '₲', 'UYU' => '$U',
        'DOP' => 'RD$', 'CUP' => '$', 'HTG' => 'G',
    ];

    /** Monedas que no se usan con centavos. */
    public const SIN_DECIMALES = ['COP', 'CLP', 'PYG'];

    public static function simbolo(?string $moneda): string
    {
        return self::SIMBOLOS[strtoupper((string) $moneda)] ?? '$';
    }

    public static function decimales(?string $moneda): int
    {
        return in_array(strtoupper((string) $moneda), self::SIN_DECIMALES, true) ? 0 : 2;
    }

    /** "S/ 10.500,00", "$ 10.500" (COP), "Bs. 1.250,50". */
    public static function formatear($monto, ?string $moneda): string
    {
        return self::simbolo($moneda) . ' '
            . number_format((float) $monto, self::decimales($moneda), ',', '.');
    }
}
