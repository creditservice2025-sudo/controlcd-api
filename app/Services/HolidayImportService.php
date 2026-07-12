<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Importa los feriados NACIONALES oficiales de un país desde Nager.Date
 * (https://date.nager.at), una fuente pública y gratuita que ya calcula los
 * feriados móviles (Semana Santa, Ley Emiliani en Colombia, etc.).
 *
 * Cada feriado se guarda como NACIONAL (company_id = null) y como fecha exacta
 * por año (recurring = false), de modo que los móviles quedan correctos. Es
 * idempotente: una sola fila por (país, fecha), así que re-importar no duplica.
 */
class HolidayImportService
{
    private const API = 'https://date.nager.at/api/v3/PublicHolidays';

    /** Nombre del país (normalizado) -> ISO 3166-1 alpha-2 que usa Nager.Date. */
    private const CODES = [
        'argentina'   => 'AR',
        'bolivia'     => 'BO',
        'brasil'      => 'BR',
        'chile'       => 'CL',
        'colombia'    => 'CO',
        'costa rica'  => 'CR',
        'cuba'        => 'CU',
        'dominicana'  => 'DO',
        'ecuador'     => 'EC',
        'el salvador' => 'SV',
        'guatemala'   => 'GT',
        'haiti'       => 'HT',
        'honduras'    => 'HN',
        'mexico'      => 'MX',
        'nicaragua'   => 'NI',
        'panama'      => 'PA',
        'paraguay'    => 'PY',
        'peru'        => 'PE',
        'puerto rico' => 'PR',
        'uruguay'     => 'UY',
        'venezuela'   => 'VE',
    ];

    /**
     * @return array{imported:int, years:array<int>, country:string, code:string}
     */
    public function import(int $countryId, ?int $fromYear = null, ?int $toYear = null): array
    {
        $country = Country::find($countryId);
        if (!$country) {
            throw new \RuntimeException('País no encontrado.');
        }

        $code = self::CODES[$this->normalize($country->name)] ?? null;
        if (!$code) {
            throw new \RuntimeException("No hay código de país disponible para \"{$country->name}\".");
        }

        $fromYear = $fromYear ?: (int) Carbon::now()->year;
        $toYear   = $toYear ?: $fromYear + 4;
        if ($toYear < $fromYear) {
            [$fromYear, $toYear] = [$toYear, $fromYear];
        }
        $toYear = min($toYear, $fromYear + 9); // cota de seguridad: máx 10 años

        $imported = 0;
        $years = [];

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $response = Http::timeout(15)->acceptJson()->get(self::API . "/{$year}/{$code}");

            if ($response->status() === 404) {
                throw new \RuntimeException("El país \"{$country->name}\" no está disponible en la fuente de feriados.");
            }
            if (!$response->successful()) {
                throw new \RuntimeException("Error consultando los feriados de {$country->name} ({$year}).");
            }

            $years[] = $year;
            foreach ($response->json() ?? [] as $item) {
                $date = $item['date'] ?? null;
                if (!$date) {
                    continue;
                }
                $description = $item['localName'] ?? ($item['name'] ?? 'Feriado');

                Holiday::updateOrCreate(
                    [
                        'company_id' => null, // nacional (compartido)
                        'country_id' => $countryId,
                        'date'       => $date,
                    ],
                    [
                        'description' => mb_substr($description, 0, 191),
                        'recurring'   => false, // fecha exacta por año (cubre los móviles)
                        'month'       => null,
                        'day'         => null,
                        'active'      => true,
                    ]
                );
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'years'    => $years,
            'country'  => $country->name,
            'code'     => $code,
        ];
    }

    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
        return strtr($name, $map);
    }
}
