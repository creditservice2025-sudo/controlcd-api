<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\Collection\CollectionTelegramLink;
use App\Services\Collection\CollectionTelegramBotService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vinculación de Telegram desde la app (Deuda & Abono): genera un enlace de un
 * solo uso para que el cobrador vincule su Telegram sin escribir su número.
 */
class CollectionTelegramLinkController extends Controller
{
    use ResolvesCollectionCompany;

    /** Genera un token de un solo uso y el deep link al bot. */
    public function token(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $username = config('services.telegram_collection.bot_username');
        if (!$username) {
            return response()->json(['message' => 'El bot de Telegram no está configurado.'], 503);
        }

        [$currency, $countryCode] = $this->context($companyId);
        $token = CollectionTelegramBotService::storeLinkToken((int) Auth::id(), $companyId, $currency, $countryCode);

        return response()->json([
            'token' => $token,
            'bot_username' => $username,
            'deep_link' => "https://t.me/{$username}?start={$token}",
            'expires_in_minutes' => 15,
        ]);
    }

    /** Estado de vinculación del usuario actual. */
    public function status(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $link = CollectionTelegramLink::where('user_id', Auth::id())
            ->whereNotNull('linked_at')->first();

        return response()->json([
            'linked' => (bool) $link,
            'linked_at' => $link?->linked_at,
            'bot_username' => config('services.telegram_collection.bot_username'),
        ]);
    }

    /** Desvincula el Telegram del usuario actual. */
    public function unlink(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        CollectionTelegramLink::where('user_id', Auth::id())->delete();

        return response()->json(['ok' => true]);
    }

    /** Moneda/país por defecto para los movimientos del cobrador. */
    private function context(int $companyId): array
    {
        $last = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNotNull('currency')->orderByDesc('id')->first();
        $currency = $last->currency ?? null;
        $countryCode = $last->country_code ?? null;

        if (!$currency) {
            $credit = CollectionCredit::where('company_id', $companyId)->orderByDesc('id')->first();
            $currency = $credit->currency ?? null;
            $countryCode = $countryCode ?: ($credit->country_code ?? null);
        }

        return [$currency ? strtoupper($currency) : 'USD', $countryCode ? strtoupper($countryCode) : null];
    }
}
