<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCompanyConfig;
use App\Traits\ApiResponse;

class CollectionConfigService
{
    use ApiResponse;

    /**
     * Universo de monedas que la empresa puede activar en Deuda & Abono.
     * Cubre todos los paises; el selector del front filtra por buscador y deja
     * los mercados de LatAm primero.
     *
     * OJO: este arreglo debe seguir espejado con worldCurrencies de
     * src/composables/useCurrencies.ts (el front necesita nombre y bandera
     * offline para tablas y dashboard). Ambos se generan del mismo dataset.
     */
    public const AVAILABLE_CURRENCIES = [
        ['code' => 'CO', 'name' => 'Colombia', 'currency' => 'COP', 'symbol' => '$'],
        ['code' => 'VE', 'name' => 'Venezuela', 'currency' => 'VES', 'symbol' => 'Bs.'],
        ['code' => 'US', 'name' => 'Estados Unidos', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'PE', 'name' => 'Peru', 'currency' => 'PEN', 'symbol' => 'S/'],
        ['code' => 'MX', 'name' => 'Mexico', 'currency' => 'MXN', 'symbol' => '$'],
        ['code' => 'EC', 'name' => 'Ecuador', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'CL', 'name' => 'Chile', 'currency' => 'CLP', 'symbol' => '$'],
        ['code' => 'AR', 'name' => 'Argentina', 'currency' => 'ARS', 'symbol' => '$'],
        ['code' => 'BR', 'name' => 'Brasil', 'currency' => 'BRL', 'symbol' => 'R$'],
        ['code' => 'PA', 'name' => 'Panama', 'currency' => 'PAB', 'symbol' => 'B/.'],
        ['code' => 'DO', 'name' => 'Rep. Dominicana', 'currency' => 'DOP', 'symbol' => 'RD$'],
        ['code' => 'UY', 'name' => 'Uruguay', 'currency' => 'UYU', 'symbol' => '$U'],
        ['code' => 'BO', 'name' => 'Bolivia', 'currency' => 'BOB', 'symbol' => 'Bs'],
        ['code' => 'CR', 'name' => 'Costa Rica', 'currency' => 'CRC', 'symbol' => '₡'],
        ['code' => 'CU', 'name' => 'Cuba', 'currency' => 'CUP', 'symbol' => '$'],
        ['code' => 'SV', 'name' => 'El Salvador', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'GT', 'name' => 'Guatemala', 'currency' => 'GTQ', 'symbol' => 'Q'],
        ['code' => 'HT', 'name' => 'Haiti', 'currency' => 'HTG', 'symbol' => 'G'],
        ['code' => 'HN', 'name' => 'Honduras', 'currency' => 'HNL', 'symbol' => 'L'],
        ['code' => 'NI', 'name' => 'Nicaragua', 'currency' => 'NIO', 'symbol' => 'C$'],
        ['code' => 'PY', 'name' => 'Paraguay', 'currency' => 'PYG', 'symbol' => '₲'],
        ['code' => 'PR', 'name' => 'Puerto Rico', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'BZ', 'name' => 'Belice', 'currency' => 'BZD', 'symbol' => 'BZ$'],
        ['code' => 'GY', 'name' => 'Guyana', 'currency' => 'GYD', 'symbol' => 'G$'],
        ['code' => 'SR', 'name' => 'Surinam', 'currency' => 'SRD', 'symbol' => '$'],
        ['code' => 'TT', 'name' => 'Trinidad y Tobago', 'currency' => 'TTD', 'symbol' => 'TT$'],
        ['code' => 'JM', 'name' => 'Jamaica', 'currency' => 'JMD', 'symbol' => 'J$'],
        ['code' => 'BS', 'name' => 'Bahamas', 'currency' => 'BSD', 'symbol' => 'B$'],
        ['code' => 'BB', 'name' => 'Barbados', 'currency' => 'BBD', 'symbol' => 'Bds$'],
        ['code' => 'ES', 'name' => 'España', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'AF', 'name' => 'Afganistan', 'currency' => 'AFN', 'symbol' => '؋'],
        ['code' => 'AL', 'name' => 'Albania', 'currency' => 'ALL', 'symbol' => 'L'],
        ['code' => 'DE', 'name' => 'Alemania', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'AD', 'name' => 'Andorra', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'AO', 'name' => 'Angola', 'currency' => 'AOA', 'symbol' => 'Kz'],
        ['code' => 'AG', 'name' => 'Antigua y Barbuda', 'currency' => 'XCD', 'symbol' => 'EC$'],
        ['code' => 'SA', 'name' => 'Arabia Saudita', 'currency' => 'SAR', 'symbol' => 'ر.س'],
        ['code' => 'DZ', 'name' => 'Argelia', 'currency' => 'DZD', 'symbol' => 'د.ج'],
        ['code' => 'AM', 'name' => 'Armenia', 'currency' => 'AMD', 'symbol' => '֏'],
        ['code' => 'AU', 'name' => 'Australia', 'currency' => 'AUD', 'symbol' => 'A$'],
        ['code' => 'AT', 'name' => 'Austria', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'AZ', 'name' => 'Azerbaiyan', 'currency' => 'AZN', 'symbol' => '₼'],
        ['code' => 'BH', 'name' => 'Barein', 'currency' => 'BHD', 'symbol' => '.د.ب'],
        ['code' => 'BD', 'name' => 'Banglades', 'currency' => 'BDT', 'symbol' => '৳'],
        ['code' => 'BE', 'name' => 'Belgica', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'BJ', 'name' => 'Benin', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'BY', 'name' => 'Bielorrusia', 'currency' => 'BYN', 'symbol' => 'Br'],
        ['code' => 'MM', 'name' => 'Birmania', 'currency' => 'MMK', 'symbol' => 'K'],
        ['code' => 'BA', 'name' => 'Bosnia y Herzegovina', 'currency' => 'BAM', 'symbol' => 'KM'],
        ['code' => 'BW', 'name' => 'Botsuana', 'currency' => 'BWP', 'symbol' => 'P'],
        ['code' => 'BN', 'name' => 'Brunei', 'currency' => 'BND', 'symbol' => 'B$'],
        ['code' => 'BG', 'name' => 'Bulgaria', 'currency' => 'BGN', 'symbol' => 'лв'],
        ['code' => 'BF', 'name' => 'Burkina Faso', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'BI', 'name' => 'Burundi', 'currency' => 'BIF', 'symbol' => 'FBu'],
        ['code' => 'BT', 'name' => 'Butan', 'currency' => 'BTN', 'symbol' => 'Nu.'],
        ['code' => 'CV', 'name' => 'Cabo Verde', 'currency' => 'CVE', 'symbol' => '$'],
        ['code' => 'KH', 'name' => 'Camboya', 'currency' => 'KHR', 'symbol' => '៛'],
        ['code' => 'CM', 'name' => 'Camerun', 'currency' => 'XAF', 'symbol' => 'FCFA'],
        ['code' => 'CA', 'name' => 'Canada', 'currency' => 'CAD', 'symbol' => 'C$'],
        ['code' => 'QA', 'name' => 'Catar', 'currency' => 'QAR', 'symbol' => 'ر.ق'],
        ['code' => 'TD', 'name' => 'Chad', 'currency' => 'XAF', 'symbol' => 'FCFA'],
        ['code' => 'CN', 'name' => 'China', 'currency' => 'CNY', 'symbol' => '¥'],
        ['code' => 'CY', 'name' => 'Chipre', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'VA', 'name' => 'Ciudad del Vaticano', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'KM', 'name' => 'Comoras', 'currency' => 'KMF', 'symbol' => 'CF'],
        ['code' => 'CG', 'name' => 'Congo', 'currency' => 'XAF', 'symbol' => 'FCFA'],
        ['code' => 'CD', 'name' => 'Congo (RD)', 'currency' => 'CDF', 'symbol' => 'FC'],
        ['code' => 'KP', 'name' => 'Corea del Norte', 'currency' => 'KPW', 'symbol' => '₩'],
        ['code' => 'KR', 'name' => 'Corea del Sur', 'currency' => 'KRW', 'symbol' => '₩'],
        ['code' => 'CI', 'name' => 'Costa de Marfil', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'HR', 'name' => 'Croacia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'DK', 'name' => 'Dinamarca', 'currency' => 'DKK', 'symbol' => 'kr'],
        ['code' => 'DM', 'name' => 'Dominica', 'currency' => 'XCD', 'symbol' => 'EC$'],
        ['code' => 'EG', 'name' => 'Egipto', 'currency' => 'EGP', 'symbol' => '£'],
        ['code' => 'AE', 'name' => 'Emiratos Arabes Unidos', 'currency' => 'AED', 'symbol' => 'د.إ'],
        ['code' => 'ER', 'name' => 'Eritrea', 'currency' => 'ERN', 'symbol' => 'Nfk'],
        ['code' => 'SK', 'name' => 'Eslovaquia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'SI', 'name' => 'Eslovenia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'EE', 'name' => 'Estonia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'SZ', 'name' => 'Esuatini', 'currency' => 'SZL', 'symbol' => 'E'],
        ['code' => 'ET', 'name' => 'Etiopia', 'currency' => 'ETB', 'symbol' => 'Br'],
        ['code' => 'PH', 'name' => 'Filipinas', 'currency' => 'PHP', 'symbol' => '₱'],
        ['code' => 'FI', 'name' => 'Finlandia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'FJ', 'name' => 'Fiyi', 'currency' => 'FJD', 'symbol' => 'FJ$'],
        ['code' => 'FR', 'name' => 'Francia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'GA', 'name' => 'Gabon', 'currency' => 'XAF', 'symbol' => 'FCFA'],
        ['code' => 'GM', 'name' => 'Gambia', 'currency' => 'GMD', 'symbol' => 'D'],
        ['code' => 'GE', 'name' => 'Georgia', 'currency' => 'GEL', 'symbol' => '₾'],
        ['code' => 'GH', 'name' => 'Ghana', 'currency' => 'GHS', 'symbol' => '₵'],
        ['code' => 'GD', 'name' => 'Granada', 'currency' => 'XCD', 'symbol' => 'EC$'],
        ['code' => 'GR', 'name' => 'Grecia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'GN', 'name' => 'Guinea', 'currency' => 'GNF', 'symbol' => 'FG'],
        ['code' => 'GQ', 'name' => 'Guinea Ecuatorial', 'currency' => 'XAF', 'symbol' => 'FCFA'],
        ['code' => 'GW', 'name' => 'Guinea-Bisau', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'HU', 'name' => 'Hungria', 'currency' => 'HUF', 'symbol' => 'Ft'],
        ['code' => 'IN', 'name' => 'India', 'currency' => 'INR', 'symbol' => '₹'],
        ['code' => 'ID', 'name' => 'Indonesia', 'currency' => 'IDR', 'symbol' => 'Rp'],
        ['code' => 'IQ', 'name' => 'Irak', 'currency' => 'IQD', 'symbol' => 'ع.د'],
        ['code' => 'IR', 'name' => 'Iran', 'currency' => 'IRR', 'symbol' => '﷼'],
        ['code' => 'IE', 'name' => 'Irlanda', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'IS', 'name' => 'Islandia', 'currency' => 'ISK', 'symbol' => 'kr'],
        ['code' => 'MH', 'name' => 'Islas Marshall', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'SB', 'name' => 'Islas Salomon', 'currency' => 'SBD', 'symbol' => 'SI$'],
        ['code' => 'IL', 'name' => 'Israel', 'currency' => 'ILS', 'symbol' => '₪'],
        ['code' => 'IT', 'name' => 'Italia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'JP', 'name' => 'Japon', 'currency' => 'JPY', 'symbol' => '¥'],
        ['code' => 'JO', 'name' => 'Jordania', 'currency' => 'JOD', 'symbol' => 'د.ا'],
        ['code' => 'KZ', 'name' => 'Kazajistan', 'currency' => 'KZT', 'symbol' => '₸'],
        ['code' => 'KE', 'name' => 'Kenia', 'currency' => 'KES', 'symbol' => 'KSh'],
        ['code' => 'KG', 'name' => 'Kirguistan', 'currency' => 'KGS', 'symbol' => '⃀'],
        ['code' => 'KI', 'name' => 'Kiribati', 'currency' => 'AUD', 'symbol' => 'A$'],
        ['code' => 'KW', 'name' => 'Kuwait', 'currency' => 'KWD', 'symbol' => 'د.ك'],
        ['code' => 'LA', 'name' => 'Laos', 'currency' => 'LAK', 'symbol' => '₭'],
        ['code' => 'LS', 'name' => 'Lesoto', 'currency' => 'LSL', 'symbol' => 'L'],
        ['code' => 'LV', 'name' => 'Letonia', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'LB', 'name' => 'Libano', 'currency' => 'LBP', 'symbol' => 'ل.ل'],
        ['code' => 'LR', 'name' => 'Liberia', 'currency' => 'LRD', 'symbol' => 'L$'],
        ['code' => 'LY', 'name' => 'Libia', 'currency' => 'LYD', 'symbol' => 'ل.د'],
        ['code' => 'LI', 'name' => 'Liechtenstein', 'currency' => 'CHF', 'symbol' => 'CHF'],
        ['code' => 'LT', 'name' => 'Lituania', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'LU', 'name' => 'Luxemburgo', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'MK', 'name' => 'Macedonia del Norte', 'currency' => 'MKD', 'symbol' => 'ден'],
        ['code' => 'MG', 'name' => 'Madagascar', 'currency' => 'MGA', 'symbol' => 'Ar'],
        ['code' => 'MY', 'name' => 'Malasia', 'currency' => 'MYR', 'symbol' => 'RM'],
        ['code' => 'MW', 'name' => 'Malaui', 'currency' => 'MWK', 'symbol' => 'MK'],
        ['code' => 'MV', 'name' => 'Maldivas', 'currency' => 'MVR', 'symbol' => 'Rf'],
        ['code' => 'ML', 'name' => 'Mali', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'MT', 'name' => 'Malta', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'MA', 'name' => 'Marruecos', 'currency' => 'MAD', 'symbol' => 'د.م.'],
        ['code' => 'MU', 'name' => 'Mauricio', 'currency' => 'MUR', 'symbol' => '₨'],
        ['code' => 'MR', 'name' => 'Mauritania', 'currency' => 'MRU', 'symbol' => 'UM'],
        ['code' => 'FM', 'name' => 'Micronesia', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'MD', 'name' => 'Moldavia', 'currency' => 'MDL', 'symbol' => 'L'],
        ['code' => 'MC', 'name' => 'Monaco', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'MN', 'name' => 'Mongolia', 'currency' => 'MNT', 'symbol' => '₮'],
        ['code' => 'ME', 'name' => 'Montenegro', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'MZ', 'name' => 'Mozambique', 'currency' => 'MZN', 'symbol' => 'MT'],
        ['code' => 'NA', 'name' => 'Namibia', 'currency' => 'NAD', 'symbol' => 'N$'],
        ['code' => 'NR', 'name' => 'Nauru', 'currency' => 'AUD', 'symbol' => 'A$'],
        ['code' => 'NP', 'name' => 'Nepal', 'currency' => 'NPR', 'symbol' => '₨'],
        ['code' => 'NG', 'name' => 'Nigeria', 'currency' => 'NGN', 'symbol' => '₦'],
        ['code' => 'NE', 'name' => 'Niger', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'NO', 'name' => 'Noruega', 'currency' => 'NOK', 'symbol' => 'kr'],
        ['code' => 'NZ', 'name' => 'Nueva Zelanda', 'currency' => 'NZD', 'symbol' => 'NZ$'],
        ['code' => 'OM', 'name' => 'Oman', 'currency' => 'OMR', 'symbol' => 'ر.ع.'],
        ['code' => 'NL', 'name' => 'Paises Bajos', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'PK', 'name' => 'Pakistan', 'currency' => 'PKR', 'symbol' => '₨'],
        ['code' => 'PW', 'name' => 'Palaos', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'PS', 'name' => 'Palestina', 'currency' => 'ILS', 'symbol' => '₪'],
        ['code' => 'PG', 'name' => 'Papua Nueva Guinea', 'currency' => 'PGK', 'symbol' => 'K'],
        ['code' => 'PL', 'name' => 'Polonia', 'currency' => 'PLN', 'symbol' => 'zł'],
        ['code' => 'PT', 'name' => 'Portugal', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'GB', 'name' => 'Reino Unido', 'currency' => 'GBP', 'symbol' => '£'],
        ['code' => 'CF', 'name' => 'Rep. Centroafricana', 'currency' => 'XAF', 'symbol' => 'FCFA'],
        ['code' => 'CZ', 'name' => 'Rep. Checa', 'currency' => 'CZK', 'symbol' => 'Kč'],
        ['code' => 'RW', 'name' => 'Ruanda', 'currency' => 'RWF', 'symbol' => 'FRw'],
        ['code' => 'RO', 'name' => 'Rumania', 'currency' => 'RON', 'symbol' => 'lei'],
        ['code' => 'RU', 'name' => 'Rusia', 'currency' => 'RUB', 'symbol' => '₽'],
        ['code' => 'WS', 'name' => 'Samoa', 'currency' => 'WST', 'symbol' => 'T'],
        ['code' => 'KN', 'name' => 'San Cristobal y Nieves', 'currency' => 'XCD', 'symbol' => 'EC$'],
        ['code' => 'SM', 'name' => 'San Marino', 'currency' => 'EUR', 'symbol' => '€'],
        ['code' => 'VC', 'name' => 'San Vicente y Granadinas', 'currency' => 'XCD', 'symbol' => 'EC$'],
        ['code' => 'LC', 'name' => 'Santa Lucia', 'currency' => 'XCD', 'symbol' => 'EC$'],
        ['code' => 'ST', 'name' => 'Santo Tome y Principe', 'currency' => 'STN', 'symbol' => 'Db'],
        ['code' => 'SN', 'name' => 'Senegal', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'RS', 'name' => 'Serbia', 'currency' => 'RSD', 'symbol' => 'дин'],
        ['code' => 'SC', 'name' => 'Seychelles', 'currency' => 'SCR', 'symbol' => '₨'],
        ['code' => 'SL', 'name' => 'Sierra Leona', 'currency' => 'SLE', 'symbol' => 'Le'],
        ['code' => 'SG', 'name' => 'Singapur', 'currency' => 'SGD', 'symbol' => 'S$'],
        ['code' => 'SY', 'name' => 'Siria', 'currency' => 'SYP', 'symbol' => '£'],
        ['code' => 'SO', 'name' => 'Somalia', 'currency' => 'SOS', 'symbol' => 'Sh'],
        ['code' => 'LK', 'name' => 'Sri Lanka', 'currency' => 'LKR', 'symbol' => '₨'],
        ['code' => 'ZA', 'name' => 'Sudafrica', 'currency' => 'ZAR', 'symbol' => 'R'],
        ['code' => 'SD', 'name' => 'Sudan', 'currency' => 'SDG', 'symbol' => 'ج.س.'],
        ['code' => 'SS', 'name' => 'Sudan del Sur', 'currency' => 'SSP', 'symbol' => '£'],
        ['code' => 'SE', 'name' => 'Suecia', 'currency' => 'SEK', 'symbol' => 'kr'],
        ['code' => 'CH', 'name' => 'Suiza', 'currency' => 'CHF', 'symbol' => 'CHF'],
        ['code' => 'TH', 'name' => 'Tailandia', 'currency' => 'THB', 'symbol' => '฿'],
        ['code' => 'TZ', 'name' => 'Tanzania', 'currency' => 'TZS', 'symbol' => 'TSh'],
        ['code' => 'TJ', 'name' => 'Tayikistan', 'currency' => 'TJS', 'symbol' => 'SM'],
        ['code' => 'TL', 'name' => 'Timor Oriental', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'TG', 'name' => 'Togo', 'currency' => 'XOF', 'symbol' => 'CFA'],
        ['code' => 'TO', 'name' => 'Tonga', 'currency' => 'TOP', 'symbol' => 'T$'],
        ['code' => 'TN', 'name' => 'Tunez', 'currency' => 'TND', 'symbol' => 'د.ت'],
        ['code' => 'TM', 'name' => 'Turkmenistan', 'currency' => 'TMT', 'symbol' => 'm'],
        ['code' => 'TR', 'name' => 'Turquia', 'currency' => 'TRY', 'symbol' => '₺'],
        ['code' => 'TV', 'name' => 'Tuvalu', 'currency' => 'AUD', 'symbol' => 'A$'],
        ['code' => 'UA', 'name' => 'Ucrania', 'currency' => 'UAH', 'symbol' => '₴'],
        ['code' => 'UG', 'name' => 'Uganda', 'currency' => 'UGX', 'symbol' => 'USh'],
        ['code' => 'UZ', 'name' => 'Uzbekistan', 'currency' => 'UZS', 'symbol' => 'so\'m'],
        ['code' => 'VU', 'name' => 'Vanuatu', 'currency' => 'VUV', 'symbol' => 'VT'],
        ['code' => 'VN', 'name' => 'Vietnam', 'currency' => 'VND', 'symbol' => '₫'],
        ['code' => 'YE', 'name' => 'Yemen', 'currency' => 'YER', 'symbol' => '﷼'],
        ['code' => 'DJ', 'name' => 'Yibuti', 'currency' => 'DJF', 'symbol' => 'Fdj'],
        ['code' => 'ZM', 'name' => 'Zambia', 'currency' => 'ZMW', 'symbol' => 'ZK'],
        ['code' => 'ZW', 'name' => 'Zimbabue', 'currency' => 'ZWG', 'symbol' => 'Z$'],
    ];

    public function getConfig(int $companyId): CollectionCompanyConfig
    {
        return CollectionCompanyConfig::firstOrCreate(
            ['company_id' => $companyId],
            [
                'currencies' => [['currency' => 'COP', 'country_code' => 'CO']],
                'default_currency' => 'COP',
                'default_country_code' => 'CO',
            ]
        );
    }

    public function updateCurrencies(int $companyId, array $currencies, string $defaultCurrency, string $defaultCountryCode)
    {
        if (empty($currencies)) {
            return $this->errorResponse('Debe seleccionar al menos una moneda', 422);
        }

        // Validar que el default esté dentro de las seleccionadas
        $defaultInList = collect($currencies)->contains(function ($pair) use ($defaultCurrency, $defaultCountryCode) {
            return $pair['currency'] === $defaultCurrency && $pair['country_code'] === $defaultCountryCode;
        });

        if (!$defaultInList) {
            return $this->errorResponse('La moneda por defecto debe estar en la lista de monedas seleccionadas', 422);
        }

        $config = $this->getConfig($companyId);
        $config->update([
            'currencies' => $currencies,
            'default_currency' => $defaultCurrency,
            'default_country_code' => $defaultCountryCode,
        ]);

        return $this->successResponse([
            'message' => 'Monedas actualizadas',
            'data' => $config->fresh(),
        ]);
    }

    public function getAvailableCurrencies(): array
    {
        return self::AVAILABLE_CURRENCIES;
    }
}
