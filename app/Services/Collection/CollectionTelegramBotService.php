<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\Collection\CollectionTelegramLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Bot de Telegram del módulo Collection (Deuda & Abono).
 *
 * Vinculación:
 *   - Deep link desde la app (/start <token>) — recomendado.
 *   - Contacto compartido — Telegram garantiza el número.
 *   - Número escrito → código de 6 dígitos por email.
 *
 * Carga guiada de movimientos: Ingreso · Gasto · Transferencia, con selección
 * de fecha (hoy u otra) y foto de comprobante opcional. Reutiliza
 * CollectionDailyRecordService::create.
 */
class CollectionTelegramBotService
{
    private ?string $token;

    private const CATEGORIES = [
        'gasto' => ['Combustible', 'Alimentación', 'Peaje', 'Mantenimiento', 'Otro'],
        'ingreso' => ['Venta', 'Cobro', 'Otro'],
    ];

    public function __construct(
        private readonly CollectionDailyRecordService $dailyRecords,
    ) {
        $this->token = config('services.telegram_collection.bot_token');
    }

    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if (!$message || empty($message['chat']['id'])) {
            return;
        }

        $chatId = (int) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        $lower = mb_strtolower($text);
        $contact = $message['contact'] ?? null;
        $photo = $message['photo'] ?? null;
        $from = $message['from'] ?? [];

        try {
            $link = CollectionTelegramLink::firstOrCreate(['chat_id' => $chatId], ['state' => 'idle']);

            // Mantener fresca la identidad de Telegram del chat (nombre/usuario)
            // para la trazabilidad de quién carga cada movimiento.
            $tgUser = $from['username'] ?? null;
            $tgFirst = $from['first_name'] ?? null;
            if ($tgUser !== $link->tg_username || $tgFirst !== $link->tg_first_name) {
                $link->tg_username = $tgUser;
                $link->tg_first_name = $tgFirst;
                $link->save();
            }

            // Vinculación por enlace de la app: /start <token>
            if (preg_match('/^\/start\s+([A-Za-z0-9]{20,64})$/', $text, $m)) {
                $this->handleStartToken($link, $m[1]);
                return;
            }

            if (!$link->isLinked()) {
                if ($link->state === 'awaiting_code' && $link->user_id) {
                    $this->handleCodeEntry($link, $text);
                } else {
                    $this->handleLinking($link, $text, $contact);
                }
                return;
            }

            // Comandos globales
            if (in_array($lower, ['/cancelar', 'cancelar', '❌ cancelar'], true)) {
                $this->resetDraft($link);
                $this->sendMainMenu($chatId, '❌ Operación cancelada. ¿Qué querés hacer?');
                return;
            }
            if ($lower === '/desvincular') {
                $link->delete();
                $this->send($chatId, '🔓 Tu Telegram se desvinculó. Enviá tu número para volver a vincular.');
                return;
            }
            if (in_array($lower, ['/start', '/ayuda', '/help', 'ayuda', '❓ ayuda', '/menu', 'menu', 'menú'], true)) {
                $this->sendMainMenu($chatId, "🤖 *Deuda & Abono* — elegí qué registrar:");
                return;
            }

            // Iniciar movimiento (por comando o por botón del menú)
            $type = $this->detectMovementType($text, $lower);
            if ($type && ($link->state === 'idle' || str_starts_with($lower, '/'))) {
                $this->startMovement($link, $chatId, $type);
                return;
            }

            $this->handleMovementFlow($link, $chatId, $text, $lower, $photo);
        } catch (\Throwable $e) {
            Log::error('[collection.telegram] error: ' . $e->getMessage(), ['chat_id' => $chatId]);
            $this->send($chatId, '⚠️ Ocurrió un error. Escribí /menu para volver a empezar.');
        }
    }

    private function detectMovementType(string $text, string $lower): ?string
    {
        if (in_array($lower, ['/gasto', 'gasto'], true) || str_contains($text, 'Gasto')) return 'gasto';
        if (in_array($lower, ['/ingreso', 'ingreso'], true) || str_contains($text, 'Ingreso')) return 'ingreso';
        if (in_array($lower, ['/transferencia', 'transferencia'], true) || str_contains($text, 'Transferencia')) return 'transferencia';
        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // Vinculación
    // ─────────────────────────────────────────────────────────────

    private function handleLinking(CollectionTelegramLink $link, string $text, ?array $contact): void
    {
        $phone = null;
        $verified = false;
        if ($contact && !empty($contact['phone_number'])) {
            $phone = $contact['phone_number'];
            $verified = true;
        } elseif (preg_match('/\+?\d[\d\s\-]{6,}\d/', $text)) {
            $phone = $text;
        }

        if (!$phone) {
            $this->sendContactRequest(
                $link->chat_id,
                "👋 *Bienvenido a Deuda & Abono.*\n\nPara cargar movimientos, vinculá tu número.\n"
                . "Tocá *Compartir mi número* (recomendado) o escribí tu teléfono."
            );
            return;
        }

        $user = $this->findUserByPhone($phone);
        if (!$user) {
            $this->send($link->chat_id, "No encontré un usuario con ese número. Verificá con tu administrador que tu teléfono esté cargado.");
            return;
        }
        $companyId = $this->resolveCompanyId($user);
        if (!$companyId) {
            $this->send($link->chat_id, "Tu usuario no tiene una empresa asociada en Collection. Contactá a tu administrador.");
            return;
        }
        [$currency, $countryCode] = $this->resolveCompanyContext($companyId, $user);
        $phoneClean = preg_replace('/\s+/', '', $phone);

        if ($verified) {
            $this->completeLink($link, $user, $companyId, $currency, $countryCode, $phoneClean);
            return;
        }

        if (empty($user->email)) {
            $this->sendContactRequest(
                $link->chat_id,
                "Ese número existe pero el usuario no tiene email para verificar. Usá *Compartir mi número*."
            );
            return;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $link->update([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'currency' => $currency,
            'country_code' => $countryCode,
            'phone' => $phoneClean,
            'verify_code' => $code,
            'verify_expires_at' => now()->addMinutes(10),
            'state' => 'awaiting_code',
            'linked_at' => null,
        ]);
        $this->emailCode($user, $code);
        $this->send(
            $link->chat_id,
            "📧 Te envié un *código de 6 dígitos* a *{$this->maskEmail($user->email)}*.\n\nIngresalo acá para vincular (vence en 10 min)."
        );
    }

    private function handleCodeEntry(CollectionTelegramLink $link, string $text): void
    {
        $code = preg_replace('/\D/', '', $text);

        if ($link->verify_expires_at && $link->verify_expires_at->isPast()) {
            $link->update(['state' => 'idle', 'verify_code' => null, 'user_id' => null, 'company_id' => null]);
            $this->send($link->chat_id, "⏱️ El código venció. Enviá tu número de nuevo para recibir uno nuevo.");
            return;
        }
        if ($code === '' || $code !== $link->verify_code) {
            $this->send($link->chat_id, "❌ Código incorrecto. Reintentá, o enviá tu número para recibir uno nuevo.");
            return;
        }

        $user = User::find($link->user_id);
        $link->update([
            'verify_code' => null, 'verify_expires_at' => null,
            'state' => 'idle', 'draft' => null, 'linked_at' => now(),
        ]);
        $this->sendWelcome($link->chat_id, $user->name ?? 'usuario');
    }

    private function completeLink(CollectionTelegramLink $link, User $user, int $companyId, ?string $currency, ?string $countryCode, string $phone): void
    {
        $link->update([
            'user_id' => $user->id, 'company_id' => $companyId,
            'currency' => $currency, 'country_code' => $countryCode, 'phone' => $phone,
            'verify_code' => null, 'verify_expires_at' => null,
            'state' => 'idle', 'draft' => null, 'linked_at' => now(),
        ]);
        $this->sendWelcome($link->chat_id, $user->name);
    }

    private function handleStartToken(CollectionTelegramLink $link, string $token): void
    {
        $data = Cache::store('file')->get(self::linkTokenKey($token));
        if (!$data || empty($data['user_id'])) {
            $this->send($link->chat_id, "⚠️ El enlace venció o no es válido. Generá uno nuevo desde la app (Deuda & Abono → *Vincular Telegram*).");
            return;
        }
        $user = User::find($data['user_id']);
        if (!$user) {
            $this->send($link->chat_id, "No pude identificar tu usuario. Generá un enlace nuevo desde la app.");
            return;
        }
        Cache::store('file')->forget(self::linkTokenKey($token));
        $phone = $user->phone ? preg_replace('/\s+/', '', $user->phone) : '';
        $this->completeLink($link, $user, (int) $data['company_id'], $data['currency'] ?? null, $data['country_code'] ?? null, $phone);
    }

    public static function linkTokenKey(string $token): string
    {
        return 'col_tg_link:' . $token;
    }

    public static function storeLinkToken(int $userId, int $companyId, ?string $currency, ?string $countryCode): string
    {
        $token = \Illuminate\Support\Str::random(40);
        Cache::store('file')->put(self::linkTokenKey($token), [
            'user_id' => $userId, 'company_id' => $companyId,
            'currency' => $currency, 'country_code' => $countryCode,
        ], now()->addMinutes(15));
        return $token;
    }

    private function findUserByPhone(string $phone): ?User
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 7) return null;
        $last8 = substr($digits, -8);
        return User::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-',''),'(','') LIKE ?",
            ['%' . $last8]
        )->first();
    }

    private function resolveCompanyId(User $user): ?int
    {
        $id = $user->company->id ?? $user->seller->company_id ?? null;
        return $id ? (int) $id : null;
    }

    private function resolveCompanyContext(int $companyId, User $user): array
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

    // ─────────────────────────────────────────────────────────────
    // Flujo de carga (ingreso / gasto / transferencia)
    // ─────────────────────────────────────────────────────────────

    private function startMovement(CollectionTelegramLink $link, int $chatId, string $type): void
    {
        $draft = ['type' => $type];
        $cajas = $this->activeCashboxes((int) $link->company_id);

        // Sin cajas habilitadas no hay dónde poner el movimiento. Antes el bot
        // igual seguía y el backend lo rechazaba recién al final, después de
        // haber pedido monto, categoría, fecha y foto.
        if (empty($cajas)) {
            $this->resetDraft($link);
            $this->send(
                $chatId,
                "⚠️ La empresa no tiene *ninguna caja habilitada*, así que no puedo "
                . "registrar el movimiento.\n\nPedile a un administrador que habilite "
                . "o cree una caja desde Registros Diarios."
            );
            return;
        }

        $head = "{$this->typeEmoji($type)} *Nuevo {$this->typeLabel($type)}*";

        // Con una sola caja no se pregunta: no hay elección que hacer y sería un
        // toque de más en cada carga. Se informa cuál es y sigue de largo.
        if (count($cajas) === 1) {
            $caja = $cajas[0];
            $draft['cashbox_id'] = (int) $caja->id;
            $draft['cashbox_name'] = $caja->name;
            $link->update(['state' => 'm_amount', 'draft' => $draft]);
            $this->send(
                $chatId,
                "{$head}\n🏦 Caja: *{$caja->name}*\n\n"
                . "💵 ¿Cuál es el *monto*? (solo el número, ej: 5000)"
            );
            return;
        }

        // La caja va PRIMERO: define dónde impacta el saldo, y preguntarla al
        // final obligaría a rehacer el movimiento si se elige la equivocada.
        $link->update(['state' => 'm_cashbox', 'draft' => $draft]);
        $this->sendCashboxButtons($chatId, $head, $cajas);
    }

    /**
     * Cajas habilitadas de la empresa, en el mismo orden que la app
     * (principal primero). Las inhabilitadas no reciben movimientos nuevos.
     *
     * @return array<int, \App\Models\Collection\CollectionCashbox>
     */
    private function activeCashboxes(int $companyId): array
    {
        return \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function sendCashboxButtons(int $chatId, string $head, array $cajas): void
    {
        // Telegram no lleva bien un teclado gigante: pasadas ~12 cajas se listan
        // los nombres y se espera que escriban, en vez de una pared de botones.
        $MAX_BOTONES = 12;
        $conBotones = array_slice($cajas, 0, $MAX_BOTONES);
        $rows = array_chunk(
            array_map(fn ($c) => ['text' => $c->name], $conBotones),
            2
        );

        $texto = "{$head}\n\n🏦 ¿En qué *caja* entra? (elegí una o escribí el nombre):";
        if (count($cajas) > $MAX_BOTONES) {
            $texto .= "\n\n_Mostrando " . $MAX_BOTONES . " de " . count($cajas)
                . " cajas. Si la tuya no está, escribí su nombre._";
        }

        $this->send($chatId, $texto, [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    /**
     * Busca una caja por lo que escribió el usuario. Compara sin acentos ni
     * mayúsculas: exacto primero, y si no, que contenga el texto —pero solo si
     * hay UNA coincidencia, para no elegir por él entre dos parecidas.
     *
     * @param  array<int, \App\Models\Collection\CollectionCashbox> $cajas
     */
    private function matchCashbox(array $cajas, string $text): ?object
    {
        // A mano y no con Normalizer: `ext-intl` no está instalada en todos los
        // entornos, y una caja llamada "Bogotá" haría reventar al bot entero.
        $norm = function (string $s): string {
            $s = mb_strtolower(trim($s), 'UTF-8');
            return strtr($s, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
                'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
                'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
                'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
                'ñ' => 'n', 'ç' => 'c',
            ]);
        };

        $q = $norm($text);
        if ($q === '') return null;

        foreach ($cajas as $c) {
            if ($norm($c->name) === $q) return $c;
        }

        $parciales = array_values(array_filter(
            $cajas,
            fn ($c) => str_contains($norm($c->name), $q)
        ));

        return count($parciales) === 1 ? $parciales[0] : null;
    }

    private function handleMovementFlow(CollectionTelegramLink $link, int $chatId, string $text, string $lower, ?array $photo): void
    {
        $draft = $link->draft ?? [];
        $type = $draft['type'] ?? 'gasto';

        switch ($link->state) {
            case 'm_cashbox':
                $cajas = $this->activeCashboxes((int) $link->company_id);
                $caja = $this->matchCashbox($cajas, $text);
                if (!$caja) {
                    // Se reenvía el teclado: si el nombre no coincidió, el
                    // cobrador se queda sin botones y sin saber qué escribir.
                    $this->sendCashboxButtons(
                        $chatId,
                        "❓ No encontré esa caja.",
                        $cajas
                    );
                    return;
                }
                $draft['cashbox_id'] = (int) $caja->id;
                $draft['cashbox_name'] = $caja->name;
                $link->update(['state' => 'm_amount', 'draft' => $draft]);
                $this->send(
                    $chatId,
                    "🏦 Caja: *{$caja->name}*\n\n"
                    . "💵 ¿Cuál es el *monto*? (solo el número, ej: 5000)"
                );
                return;

            case 'm_amount':
                $amount = $this->parseAmount($text);
                if ($amount === null || $amount <= 0) {
                    $this->send($chatId, 'Ingresá un *monto* válido (solo número, ej: 5000).');
                    return;
                }
                $draft['amount'] = $amount;
                if ($type === 'transferencia') {
                    $link->update(['state' => 'm_destination', 'draft' => $draft]);
                    $this->sendTransferDestinations($chatId, (int) $link->company_id);
                } else {
                    $link->update(['state' => 'm_category', 'draft' => $draft]);
                    $this->sendCategoryButtons($chatId, $type);
                }
                return;

            case 'm_destination':
                $draft['destination'] = mb_substr($text, 0, 100);
                $link->update(['state' => 'm_description', 'draft' => $draft]);
                $this->send($chatId, "📝 ¿Alguna *descripción*? (o *-* para omitir)");
                return;

            case 'm_category':
                $draft['category'] = mb_substr($text, 0, 50);
                $link->update(['state' => 'm_description', 'draft' => $draft]);
                $this->send($chatId, "📝 ¿Alguna *descripción*? (o *-* para omitir)");
                return;

            case 'm_description':
                $draft['description'] = ($text === '-' || $text === '') ? null : mb_substr($text, 0, 500);
                $link->update(['state' => 'm_date', 'draft' => $draft]);
                $this->sendDateButtons($chatId);
                return;

            case 'm_date':
                if (str_contains($lower, 'hoy')) {
                    $draft['recorded_at'] = null; // el servidor usa "ahora"
                    $link->update(['state' => 'm_photo', 'draft' => $draft]);
                    $this->send($chatId, $this->summary($draft, $link) . "\n\n📷 Enviá la *foto del comprobante*, o *LISTO* para guardar.");
                } elseif (str_contains($lower, 'otra')) {
                    $link->update(['state' => 'm_date_input', 'draft' => $draft]);
                    $this->send($chatId, $this->dateHelpText());
                } else {
                    $this->sendDateButtons($chatId);
                }
                return;

            case 'm_date_input':
                $parsed = $this->parseCustomDate($text);
                if (!$parsed) {
                    $this->send($chatId, $this->dateHelpText(true));
                    return;
                }
                $draft['recorded_at'] = $parsed;
                $link->update(['state' => 'm_photo', 'draft' => $draft]);
                $this->send($chatId, $this->summary($draft, $link) . "\n\n📷 Enviá la *foto del comprobante*, o *LISTO* para guardar.");
                return;

            case 'm_photo':
                if ($photo) {
                    $fileId = $this->largestPhotoId($photo);
                    $upload = $fileId ? $this->downloadPhoto($fileId) : null;
                    $this->finalizeMovement($link, $chatId, $draft, $upload);
                    return;
                }
                if (in_array($lower, ['listo', '/listo', 'guardar'], true)) {
                    $this->finalizeMovement($link, $chatId, $draft, null);
                    return;
                }
                $this->send($chatId, "Enviá una *foto* del comprobante o escribí *LISTO* para guardar sin foto.");
                return;

            default:
                $this->sendMainMenu($chatId, "Elegí qué registrar:");
        }
    }

    private function finalizeMovement(CollectionTelegramLink $link, int $chatId, array $draft, ?UploadedFile $upload): void
    {
        $user = User::find($link->user_id);
        if (!$user) {
            $this->resetDraft($link);
            $this->send($chatId, "No pude identificar tu usuario. Escribí tu número para revincular.");
            return;
        }
        $type = $draft['type'] ?? 'gasto';

        Auth::login($user);
        $req = Request::create('/collection/telegram', 'POST', array_filter([
            'company_id' => $link->company_id,
            'type' => $type,
            'amount' => $draft['amount'],
            'currency' => $link->currency ?: 'USD',
            'country_code' => $link->country_code,
            // Caja elegida en el flujo. Sin esto el movimiento caía siempre en
            // la caja principal, sin que quien lo cargaba lo supiera.
            // (currency y country_code los recalcula create() desde la caja.)
            'cashbox_id' => $draft['cashbox_id'] ?? null,
            'category' => $draft['category'] ?? null,
            'description' => $draft['description'] ?? null,
            'recorded_at' => $draft['recorded_at'] ?? null,
            'transfer_to' => $draft['destination'] ?? null,
        ], fn ($v) => $v !== null));

        if ($upload) {
            $req->files->set('evidence', [$upload]);
        }

        $resp = $this->dailyRecords->create($req);
        $ok = method_exists($resp, 'getStatusCode') && $resp->getStatusCode() < 300;

        // Marcar el movimiento con su ORIGEN (Telegram) y el número que lo cargó,
        // para trazabilidad. No se toca create(); se anota en el metadata luego.
        if ($ok && method_exists($resp, 'getContent')) {
            $data = json_decode($resp->getContent(), true);
            $recId = $data['data']['id'] ?? null;
            if ($recId) {
                $rec = CollectionDailyRecord::find($recId);
                if ($rec) {
                    $meta = is_array($rec->metadata) ? $rec->metadata : [];
                    $meta['source'] = 'telegram';
                    // Identidad de Telegram de quien cargó (nombre / @usuario).
                    $meta['telegram_user'] = $link->tg_username
                        ? '@' . $link->tg_username
                        : ($link->tg_first_name ?: null);
                    // Teléfono del perfil (puede no coincidir con el de Telegram).
                    $meta['telegram_phone'] = $link->phone ?: null;
                    $rec->metadata = $meta;
                    $rec->save();
                }
            }
        }

        $this->resetDraft($link);

        if ($ok) {
            $foto = $upload ? "\n📎 Comprobante adjuntado." : '';
            $this->send($chatId, "✅ *{$this->typeLabel($type)} registrado*\n" . $this->summary($draft, $link, false) . $foto);
            $this->sendMainMenu($chatId, "¿Registrás otro?");
        } else {
            $reason = '';
            if (method_exists($resp, 'getContent')) {
                $data = json_decode($resp->getContent(), true);
                $reason = $data['message'] ?? '';
            }
            $this->send($chatId, "⚠️ No se pudo registrar." . ($reason ? "\n_{$reason}_" : ' Escribí /menu para reintentar.'));
        }
    }

    /** Resumen del movimiento para confirmar antes de guardar. */
    private function summary(array $draft, CollectionTelegramLink $link, bool $header = true): string
    {
        $type = $draft['type'] ?? 'gasto';
        $monto = number_format((float) ($draft['amount'] ?? 0), 2, ',', '.');
        $fecha = !empty($draft['recorded_at'])
            ? \Carbon\Carbon::parse($draft['recorded_at'])->format('d/m/Y H:i')
            : 'Hoy';
        $lines = [];
        if ($header) $lines[] = "{$this->typeEmoji($type)} *Revisá el {$this->typeLabel($type)}:*";
        // La caja va arriba de todo: es lo primero que hay que poder verificar
        // en el comprobante, porque es donde quedó la plata.
        if (!empty($draft['cashbox_name'])) $lines[] = "🏦 Caja: *{$draft['cashbox_name']}*";
        $lines[] = "💵 Monto: *{$link->currency} {$monto}*";
        if ($type === 'transferencia' && !empty($draft['destination'])) {
            $lines[] = "🔄 Destino: {$draft['destination']}";
        }
        if (!empty($draft['category'])) $lines[] = "🏷️ Categoría: {$draft['category']}";
        if (!empty($draft['description'])) $lines[] = "📝 {$draft['description']}";
        $lines[] = "📅 Fecha: {$fecha}";
        return implode("\n", $lines);
    }

    private function typeEmoji(string $t): string
    {
        return $t === 'ingreso' ? '💰' : ($t === 'transferencia' ? '🔄' : '💸');
    }

    private function typeLabel(string $t): string
    {
        return $t === 'ingreso' ? 'Ingreso' : ($t === 'transferencia' ? 'Transferencia' : 'Gasto');
    }

    private function parseAmount(string $text): ?float
    {
        $clean = preg_replace('/[^\d.,]/', '', $text);
        if ($clean === '') return null;
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Parser de fecha tolerante: acepta separadores / - . o espacio, año de 2 o
     * 4 dígitos, y hora opcional (HH:mm, HH.mm o HHhmm). Orden día/mes/año.
     */
    private function parseCustomDate(string $text): ?string
    {
        $t = trim($text);
        if (!preg_match('#^(\d{1,2})[/\-.\s](\d{1,2})[/\-.\s](\d{2,4})(?:[\sT]+(\d{1,2})[:.h](\d{2}))?$#i', $t, $m)) {
            return null;
        }
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) $y += 2000;                 // 26 → 2026
        $h = (isset($m[4]) && $m[4] !== '') ? (int) $m[4] : 12;
        $min = (isset($m[5]) && $m[5] !== '') ? (int) $m[5] : 0;
        if (!checkdate($mo, $d, $y) || $h > 23 || $min > 59) return null;
        return sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $h, $min);
    }

    /** Texto de ayuda para escribir la fecha, con una fecha copiable para editar. */
    private function dateHelpText(bool $invalid = false): string
    {
        $tpl = now()->format('d/m/Y H:i');
        $pre = $invalid ? "No entendí la fecha. " : '';
        return $pre
            . "✏️ Escribí la *fecha* (día/mes/año). Podés agregar la *hora* (opcional).\n\n"
            . "Ejemplos:\n`15/07/2026`  (sin hora)\n`15/07/2026 14:30`  (con hora)\n\n"
            . "O tocá esta para copiarla y editarla 👇\n`{$tpl}`";
    }

    private function resetDraft(CollectionTelegramLink $link): void
    {
        $link->update(['state' => 'idle', 'draft' => null]);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        return mb_substr($name, 0, 2) . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }

    private function emailCode(User $user, string $code): void
    {
        try {
            Mail::raw(
                "Tu código para vincular Telegram con Deuda & Abono es: {$code}\n\n"
                . "Vence en 10 minutos. Si no lo solicitaste, ignorá este correo.",
                function ($m) use ($user) {
                    $m->to($user->email)->subject('Código de vinculación · Deuda & Abono');
                }
            );
        } catch (\Throwable $e) {
            Log::error('[collection.telegram] emailCode falló: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Telegram API + teclados
    // ─────────────────────────────────────────────────────────────

    private function sendWelcome(int $chatId, string $name): void
    {
        $this->send(
            $chatId,
            "✅ ¡Listo, *{$name}*! Tu Telegram quedó vinculado.\n\n"
            . "Registrá movimientos de caja al instante 👇"
        );
        $this->sendMainMenu($chatId, "Elegí qué registrar:");
    }

    private function sendMainMenu(int $chatId, string $text): void
    {
        $this->send($chatId, $text, [
            'keyboard' => [
                [['text' => '💰 Ingreso'], ['text' => '💸 Gasto']],
                [['text' => '🔄 Transferencia'], ['text' => '❓ Ayuda']],
            ],
            'resize_keyboard' => true,
        ]);
    }

    private function sendDateButtons(int $chatId): void
    {
        $hoy = now()->format('d/m/Y');
        $this->send($chatId, "📅 ¿Con qué *fecha* lo registrás?", [
            'keyboard' => [[['text' => "📅 Hoy ({$hoy})"], ['text' => '✏️ Otra fecha']]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    private function send(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        if (!$this->token) {
            Log::warning('[collection.telegram] sin bot_token; no se envía');
            return;
        }
        $payload = ['chat_id' => $chatId, 'text' => str_replace('\n', "\n", $text), 'parse_mode' => 'Markdown'];
        if ($replyMarkup) $payload['reply_markup'] = $replyMarkup;
        try {
            // Con timeout: sin él, una red lenta cuelga el request hasta que PHP
            // lo mata. El mensaje es secundario, la operación no puede depender de él.
            Http::withoutVerifying()
                ->connectTimeout(3)
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$this->token}/sendMessage", $payload);
        } catch (\Throwable $e) {
            Log::error('[collection.telegram] sendMessage falló: ' . $e->getMessage());
        }
    }

    private function sendContactRequest(int $chatId, string $text): void
    {
        $this->send($chatId, $text, [
            'keyboard' => [[['text' => '📱 Compartir mi número', 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    private function sendCategoryButtons(int $chatId, string $type): void
    {
        $cats = self::CATEGORIES[$type] ?? self::CATEGORIES['gasto'];
        $rows = array_chunk(array_map(fn ($c) => ['text' => $c], $cats), 2);
        $this->send($chatId, "🏷️ Elegí una *categoría* (o escribila):", [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    /**
     * Cuentas destino para transferencias: las que la empresa ya usó (destinos
     * de transferencias previas) + las por defecto de la app. Así el cobrador
     * elige con un toque en vez de escribir.
     */
    private function transferAccounts(int $companyId): array
    {
        $accounts = [];
        $recent = CollectionDailyRecord::where('company_id', $companyId)
            ->where('type', 'transferencia')
            ->whereNull('deleted_at')
            ->orderByDesc('id')->limit(50)->get(['metadata']);
        foreach ($recent as $r) {
            $to = is_array($r->metadata) ? ($r->metadata['transfer_to'] ?? null) : null;
            if ($to && !in_array($to, $accounts, true)) $accounts[] = $to;
        }
        // Defaults de la app (mismos que settings.transferAccounts).
        foreach (['Ahorros', 'Efectivo', 'Tarjeta'] as $d) {
            if (!in_array($d, $accounts, true)) $accounts[] = $d;
        }
        return array_slice($accounts, 0, 8);
    }

    private function sendTransferDestinations(int $chatId, int $companyId): void
    {
        $accounts = $this->transferAccounts($companyId);
        $rows = array_chunk(array_map(fn ($a) => ['text' => $a], $accounts), 2);
        $this->send($chatId, "🔄 ¿A qué *cuenta/destino* va? (elegí una o escribí otra):", [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    private function largestPhotoId(array $photo): ?string
    {
        $last = end($photo);
        return $last['file_id'] ?? null;
    }

    private function downloadPhoto(string $fileId): ?UploadedFile
    {
        if (!$this->token) return null;
        try {
            $meta = Http::withoutVerifying()
                ->connectTimeout(3)
                ->timeout(10)
                ->get("https://api.telegram.org/bot{$this->token}/getFile", ['file_id' => $fileId])->json();
            $path = $meta['result']['file_path'] ?? null;
            if (!$path) return null;
            // Descarga de la foto: más margen que un mensaje, pero acotado igual.
            $binary = Http::withoutVerifying()
                ->connectTimeout(3)
                ->timeout(20)
                ->get("https://api.telegram.org/file/bot{$this->token}/{$path}")->body();
            if (!$binary) return null;
            $tmp = tempnam(sys_get_temp_dir(), 'tgc') . '.jpg';
            file_put_contents($tmp, $binary);
            return new UploadedFile($tmp, 'comprobante.jpg', 'image/jpeg', null, true);
        } catch (\Throwable $e) {
            Log::error('[collection.telegram] downloadPhoto falló: ' . $e->getMessage());
            return null;
        }
    }
}
