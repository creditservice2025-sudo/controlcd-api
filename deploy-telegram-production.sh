#!/usr/bin/env bash
# =============================================================================
# Deploy Telegram en producción — Controll CD
# =============================================================================
# Uso:
#   1. Editá las 4 variables del bloque CONFIG abajo.
#   2. Subí este archivo al servidor de prod (ej: scp).
#   3. SSH al server y ejecutar:
#        cd /ruta/controlcd-api
#        chmod +x deploy-telegram-production.sh
#        ./deploy-telegram-production.sh
#
# El script es IDEMPOTENTE: si algo se ejecutó antes, lo detecta y salta.
# Si algún paso crítico falla, el script aborta con código != 0.
#
# Requisitos:
#   - bash 4+
#   - php artisan disponible
#   - curl, jq, mysqldump, openssl
#   - Acceso de escritura a .env y storage/
# =============================================================================

set -euo pipefail

# ─── CONFIG: EDITAR ESTAS 4 VARIABLES ANTES DE EJECUTAR ─────────────────────

API_URL="https://api.control-cd.com"                                      # URL pública de TU API
BOT_TOKEN="8975356472:AAHUz8a33RayRkQc0Bo-7Qs9MOQT87e5FJo"                # Token bot @ControlCDNotifEmpBot
BOT_USERNAME="ControlCDNotifEmpBot"                                        # Sin el @
ENABLE_QUEUE_WORKER="no"                                                   # "yes" para activar queue=database + Supervisor
WEBHOOK_SECRET="dev_secret_local_change_in_prod"


SET_RESPONSE="$(curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
    -H "Content-Type: application/json" \
    -d "{
        \"url\": \"${API_URL}/api/telegram/webhook\",
        \"secret_token\": \"${WEBHOOK_SECRET}\",
        \"allowed_updates\": [\"message\"]
    }")"



if echo "$SET_RESPONSE" | grep -q '"ok":true'; then
    ok "setWebhook OK."
else
    err "setWebhook FALLÓ: $SET_RESPONSE"
    die "Telegram rechazó el webhook. Validar HTTPS público y certificado válido."
fi

log "Verificando con getWebhookInfo..."
INFO_RESPONSE="$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo")"
echo "$INFO_RESPONSE" >> "$LOG_FILE"

if command -v jq >/dev/null; then
    echo "$INFO_RESPONSE" | jq .
    LAST_ERROR="$(echo "$INFO_RESPONSE" | jq -r '.result.last_error_message // empty')"
else
    echo "$INFO_RESPONSE"
    LAST_ERROR="$(echo "$INFO_RESPONSE" | grep -oP '"last_error_message":"[^"]+"' || true)"
fi

if [[ -n "$LAST_ERROR" ]]; then
    warn "getWebhookInfo reporta error previo: $LAST_ERROR"
    warn "El registro se hizo, pero Telegram tuvo problemas para llegar a tu URL."
    warn "Causas comunes: HTTPS inválido, firewall bloqueando puertos 443/80/88/8443."
else
    ok "Webhook activo sin errores reportados."
fi


# ─── 7. Validación final ────────────────────────────────────────────────────
step "7. Validación final"

log "Probando llamada directa al bot..."
BOT_TEST="$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getMe")"
if echo "$BOT_TEST" | grep -q '"ok":true'; then
    BOT_NAME="$(echo "$BOT_TEST" | grep -oP '"first_name":"[^"]+"' | head -1 || true)"
    ok "Bot responde: $BOT_NAME"
else
    warn "getMe del bot falló: $BOT_TEST"
fi

log "Buscando una empresa vinculada para test end-to-end..."
TEST_RESULT="$(php artisan tinker --execute="
\$c = App\Models\Company::where('telegram_chat_id', '!=', null)
    ->where('telegram_feature_enabled', true)
    ->where('telegram_enabled', true)
    ->first();
if (!\$c) { echo 'NO_LINKED_COMPANY'; exit; }
echo 'TESTING_WITH=' . \$c->name . PHP_EOL;
\$r = app(App\Services\TelegramService::class)->sendToCompany(\$c, '🧪 Deploy prod OK ' . now(), 'deploy_test');
echo \$r ? 'SEND_OK' : 'SEND_FAIL';
" 2>&1)"
echo "$TEST_RESULT" | tee -a "$LOG_FILE"

if echo "$TEST_RESULT" | grep -q "SEND_OK"; then
    ok "Envío real OK. Revisá Telegram para confirmar recepción."
elif echo "$TEST_RESULT" | grep -q "NO_LINKED_COMPANY"; then
    warn "No hay empresas vinculadas todavía. Vincular desde el panel para validar end-to-end."
else
    warn "Test de envío reportó fallo. Revisar storage/logs/laravel.log"
fi

# ─── Resumen final ──────────────────────────────────────────────────────────
step "RESUMEN"

cat <<EOF | tee -a "$LOG_FILE"

  Deploy completado.

  Variables .env actualizadas:
    TELEGRAM_NOTIFICATIONS_BOT_TOKEN       = [SET]
    TELEGRAM_NOTIFICATIONS_BOT_USERNAME    = $BOT_USERNAME
    TELEGRAM_WEBHOOK_SECRET                = $WEBHOOK_SECRET

  Webhook registrado:
    ${API_URL}/api/telegram/webhook


  Log:
    $LOG_FILE

  Próximos pasos:
    1. Probar vinculación desde el panel SA (habilitar empresa) + admin (vincular).
    2. Crear un cliente real y confirmar que llega la notificación.
    3. Si todo OK, archivar este log y el backup en lugar seguro.

EOF

ok "Hecho."
