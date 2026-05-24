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

# ─── No editar de acá para abajo ────────────────────────────────────────────

readonly SCRIPT_NAME="$(basename "$0")"
readonly TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
readonly BACKUP_FILE="backup_pre_telegram_${TIMESTAMP}.sql"
readonly LOG_FILE="deploy-telegram-${TIMESTAMP}.log"

# Colores para output legible
if [[ -t 1 ]]; then
    readonly C_OK="\033[1;32m"
    readonly C_WARN="\033[1;33m"
    readonly C_ERR="\033[1;31m"
    readonly C_INFO="\033[1;34m"
    readonly C_RESET="\033[0m"
else
    readonly C_OK="" C_WARN="" C_ERR="" C_INFO="" C_RESET=""
fi

log() { printf "${C_INFO}[%s]${C_RESET} %s\n" "$(date +%H:%M:%S)" "$*" | tee -a "$LOG_FILE"; }
ok()  { printf "${C_OK}[OK]${C_RESET} %s\n" "$*" | tee -a "$LOG_FILE"; }
warn(){ printf "${C_WARN}[WARN]${C_RESET} %s\n" "$*" | tee -a "$LOG_FILE"; }
err() { printf "${C_ERR}[ERROR]${C_RESET} %s\n" "$*" | tee -a "$LOG_FILE" >&2; }
die() { err "$*"; err "Abortando. Revisar $LOG_FILE"; exit 1; }

step() { printf "\n${C_INFO}━━━ %s ━━━${C_RESET}\n" "$*" | tee -a "$LOG_FILE"; }

# ─── 0. Validaciones previas ────────────────────────────────────────────────
step "0. Verificaciones previas"

[[ -f artisan ]] || die "No se encuentra 'artisan'. Ejecutar desde la raíz del proyecto Laravel."
[[ -f .env ]]    || die "No se encuentra '.env'. Falta el archivo de configuración."

command -v php       >/dev/null || die "php no instalado."
command -v curl      >/dev/null || die "curl no instalado."
command -v openssl   >/dev/null || die "openssl no instalado."
command -v mysqldump >/dev/null || die "mysqldump no instalado."

if ! command -v jq >/dev/null; then
    warn "jq no instalado — el output del webhook se mostrará crudo."
fi

# Validar config del script
[[ "$API_URL" == https://* ]] || die "API_URL debe empezar con https:// (Telegram exige HTTPS)."
[[ -n "$BOT_TOKEN" ]]         || die "BOT_TOKEN vacío."
[[ -n "$BOT_USERNAME" ]]      || die "BOT_USERNAME vacío."
[[ "$BOT_TOKEN" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]] || die "BOT_TOKEN parece mal formado."

ok "Validaciones OK. Trabajando en $(pwd)"
log "Backup será: $BACKUP_FILE"
log "Log de esta corrida: $LOG_FILE"

# ─── 1. Backup BD ───────────────────────────────────────────────────────────
step "1. Backup BD"

DB_USER="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"')"
DB_PASS="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')"
DB_NAME="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"')"
DB_HOST="$(grep -E '^DB_HOST='     .env | cut -d= -f2- | tr -d '"')"
DB_HOST="${DB_HOST:-127.0.0.1}"

[[ -n "$DB_USER" && -n "$DB_NAME" ]] || die "No se pudieron leer credenciales DB del .env"

log "Backup de '$DB_NAME' en $DB_HOST como $DB_USER → $BACKUP_FILE"
if [[ -n "$DB_PASS" ]]; then
    MYSQL_PWD="$DB_PASS" mysqldump -u "$DB_USER" -h "$DB_HOST" \
        --single-transaction --quick --routines --triggers \
        "$DB_NAME" > "$BACKUP_FILE" 2>>"$LOG_FILE"
else
    mysqldump -u "$DB_USER" -h "$DB_HOST" \
        --single-transaction --quick --routines --triggers \
        "$DB_NAME" > "$BACKUP_FILE" 2>>"$LOG_FILE"
fi

SIZE=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || stat -f%z "$BACKUP_FILE")
[[ "$SIZE" -gt 1024 ]] || die "El backup pesa $SIZE bytes — falló silenciosamente. Revisar $LOG_FILE"
ok "Backup OK ($(du -h "$BACKUP_FILE" | cut -f1))"

# ─── 2. Variables .env Telegram ─────────────────────────────────────────────
step "2. Variables .env Telegram"

# Detectar si ya existen (para no duplicar)
EXISTING_TOKEN="$(grep -c '^TELEGRAM_NOTIFICATIONS_BOT_TOKEN=' .env || true)"
EXISTING_USER="$(grep -c '^TELEGRAM_NOTIFICATIONS_BOT_USERNAME=' .env || true)"
EXISTING_SECRET="$(grep -c '^TELEGRAM_WEBHOOK_SECRET=' .env || true)"

# Manejar el secret: si ya hay uno válido, lo reutilizamos. Si no, generamos.
if [[ "$EXISTING_SECRET" -gt 0 ]]; then
    WEBHOOK_SECRET="$(grep '^TELEGRAM_WEBHOOK_SECRET=' .env | cut -d= -f2- | tr -d '"')"
    if [[ ${#WEBHOOK_SECRET} -ge 32 ]]; then
        log "TELEGRAM_WEBHOOK_SECRET ya existe (length=${#WEBHOOK_SECRET}). Reutilizando."
    else
        warn "TELEGRAM_WEBHOOK_SECRET es muy corto (${#WEBHOOK_SECRET}). Regenerando."
        WEBHOOK_SECRET="$(openssl rand -hex 32)"
        sed -i.bak "s|^TELEGRAM_WEBHOOK_SECRET=.*|TELEGRAM_WEBHOOK_SECRET=$WEBHOOK_SECRET|" .env
    fi
else
    WEBHOOK_SECRET="$(openssl rand -hex 32)"
    echo "TELEGRAM_WEBHOOK_SECRET=$WEBHOOK_SECRET" >> .env
    ok "TELEGRAM_WEBHOOK_SECRET generado y agregado."
fi

if [[ "$EXISTING_TOKEN" -gt 0 ]]; then
    sed -i.bak "s|^TELEGRAM_NOTIFICATIONS_BOT_TOKEN=.*|TELEGRAM_NOTIFICATIONS_BOT_TOKEN=$BOT_TOKEN|" .env
    ok "TELEGRAM_NOTIFICATIONS_BOT_TOKEN actualizado."
else
    echo "TELEGRAM_NOTIFICATIONS_BOT_TOKEN=$BOT_TOKEN" >> .env
    ok "TELEGRAM_NOTIFICATIONS_BOT_TOKEN agregado."
fi

if [[ "$EXISTING_USER" -gt 0 ]]; then
    sed -i.bak "s|^TELEGRAM_NOTIFICATIONS_BOT_USERNAME=.*|TELEGRAM_NOTIFICATIONS_BOT_USERNAME=$BOT_USERNAME|" .env
    ok "TELEGRAM_NOTIFICATIONS_BOT_USERNAME actualizado."
else
    echo "TELEGRAM_NOTIFICATIONS_BOT_USERNAME=$BOT_USERNAME" >> .env
    ok "TELEGRAM_NOTIFICATIONS_BOT_USERNAME agregado."
fi

# Limpieza de .env.bak generado por sed
rm -f .env.bak

# IMPORTANTE: anotar el secret en un lugar seguro
warn "════════════════════════════════════════════════════════════════"
warn "GUARDA ESTE SECRET EN TU PASSWORD MANAGER:"
warn "TELEGRAM_WEBHOOK_SECRET=$WEBHOOK_SECRET"
warn "Si lo perdés, hay que correr setWebhook de nuevo."
warn "════════════════════════════════════════════════════════════════"

# ─── 3. Migraciones ─────────────────────────────────────────────────────────
step "3. Migraciones"

log "Aplicando migraciones pendientes..."
php artisan migrate --force 2>&1 | tee -a "$LOG_FILE"

# Verificar las 7 telegram_*
TELEGRAM_RAN="$(php artisan migrate:status 2>/dev/null | grep -ciE 'telegram.*Ran' || true)"
log "Migraciones telegram con estado Ran: $TELEGRAM_RAN (esperado >= 7)"
[[ "$TELEGRAM_RAN" -ge 7 ]] || warn "Menos migraciones Ran de las esperadas. Revisar manualmente."

ok "Migraciones aplicadas."

# ─── 4. Limpiar y cachear config ────────────────────────────────────────────
step "4. Limpiar y cachear config"

php artisan config:clear >>"$LOG_FILE" 2>&1
php artisan route:clear  >>"$LOG_FILE" 2>&1
php artisan cache:clear  >>"$LOG_FILE" 2>&1
php artisan view:clear   >>"$LOG_FILE" 2>&1
php artisan config:cache >>"$LOG_FILE" 2>&1
php artisan route:cache  >>"$LOG_FILE" 2>&1

# Validar que la config se leyó
CONFIG_CHECK="$(php artisan tinker --execute="
echo config('services.telegram.notifications_bot_token') ? 'TOKEN_OK' : 'TOKEN_MISSING';
echo PHP_EOL;
echo config('services.telegram.webhook_secret') ? 'SECRET_OK' : 'SECRET_MISSING';
" 2>/dev/null)"

echo "$CONFIG_CHECK" | tee -a "$LOG_FILE"
echo "$CONFIG_CHECK" | grep -q "TOKEN_OK"  || die "Config no cargó TELEGRAM_NOTIFICATIONS_BOT_TOKEN"
echo "$CONFIG_CHECK" | grep -q "SECRET_OK" || die "Config no cargó TELEGRAM_WEBHOOK_SECRET"
ok "Config cacheada y verificada."

# ─── 5. Registrar webhook en Telegram ───────────────────────────────────────
step "5. Registrar webhook en Telegram"

# Borrar el viejo (idempotente, no falla si no había)
log "Borrando webhook previo si existe..."
curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/deleteWebhook" >> "$LOG_FILE" 2>&1
echo "" >> "$LOG_FILE"

log "Registrando webhook a ${API_URL}/api/telegram/webhook ..."
SET_RESPONSE="$(curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
    -H "Content-Type: application/json" \
    -d "{
        \"url\": \"${API_URL}/api/telegram/webhook\",
        \"secret_token\": \"${WEBHOOK_SECRET}\",
        \"allowed_updates\": [\"message\"]
    }")"

echo "setWebhook response: $SET_RESPONSE" >> "$LOG_FILE"

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

# ─── 6. (Opcional) Queue worker con Supervisor ──────────────────────────────
if [[ "$ENABLE_QUEUE_WORKER" == "yes" ]]; then
    step "6. Configurar queue worker con Supervisor"

    if ! command -v supervisorctl >/dev/null; then
        warn "supervisorctl no instalado. Salteo este paso. Instalá supervisor y volvé a correr."
    else
        sed -i.bak 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' .env
        rm -f .env.bak
        php artisan config:cache >>"$LOG_FILE" 2>&1
        php artisan migrate --force >>"$LOG_FILE" 2>&1 # asegurar tablas jobs/failed_jobs

        SUPERVISOR_CONF="/etc/supervisor/conf.d/controlcd-queue.conf"
        if [[ -f "$SUPERVISOR_CONF" ]]; then
            warn "Ya existe $SUPERVISOR_CONF, no lo sobrescribo."
        else
            sudo tee "$SUPERVISOR_CONF" > /dev/null <<EOF
[program:controlcd-queue]
process_name=%(program_name)s_%(process_num)02d
command=php $(pwd)/artisan queue:work --tries=3 --timeout=30 --sleep=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/controlcd-queue.log
stopwaitsecs=3600
EOF
            sudo supervisorctl reread
            sudo supervisorctl update
            sudo supervisorctl start controlcd-queue:*
            ok "Supervisor configurado."
        fi

        sudo supervisorctl status controlcd-queue:* | tee -a "$LOG_FILE"
    fi
else
    step "6. Queue worker (omitido)"
    log "ENABLE_QUEUE_WORKER='no' — manteniendo QUEUE_CONNECTION sin cambios."
    log "Las notificaciones se envían INLINE (suman latencia al request del usuario)."
    log "Para activar el async, ejecutá de nuevo el script con ENABLE_QUEUE_WORKER='yes'."
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

  Backup:
    $BACKUP_FILE

  Log:
    $LOG_FILE

  Próximos pasos:
    1. Probar vinculación desde el panel SA (habilitar empresa) + admin (vincular).
    2. Crear un cliente real y confirmar que llega la notificación.
    3. Si todo OK, archivar este log y el backup en lugar seguro.

EOF

ok "Hecho."
