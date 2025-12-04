#!/bin/bash
#
# Script de Migración de Pagos en Cache
# ControCD Backend - Payment Cache Migration
#
# Este script debe ejecutarse ANTES del despliegue completo
# para migrar los abonos que están en Redis cache al nuevo sistema
#

set -e

# Configuración (igual que deploy-to-server.sh)
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
SERVER_USER="root"
SERVER_IP="146.190.147.164"
SERVER_PATH="/var/www/controlcd-api"
LOCAL_PATH="/home/mario-d-az/git/ControCD-Backend"

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=============================================="
echo "ControCD - Migración de Pagos en Cache"
echo "STAGING Server"
echo -e "==============================================${NC}"
echo ""

# Verificar que estamos en el directorio correcto
if [ ! -f "$LOCAL_PATH/artisan" ]; then
    echo -e "${RED}Error: No se encuentra el archivo artisan en $LOCAL_PATH${NC}"
    exit 1
fi

# Verificar que la clave SSH existe
if [ ! -f "$SSH_KEY" ]; then
    echo -e "${RED}Error: No se encuentra la clave SSH en $SSH_KEY${NC}"
    exit 1
fi

echo -e "${YELLOW}Configuración:${NC}"
echo "  Servidor: $SERVER_USER@$SERVER_IP"
echo "  Ruta remota: $SERVER_PATH"
echo "  Ruta local: $LOCAL_PATH"
echo ""

echo -e "${YELLOW}Este script realizará:${NC}"
echo "  1. Subir comandos de migración al servidor"
echo "  2. Extraer y respaldar datos del cache Redis"
echo "  3. Aplicar migraciones de base de datos"
echo "  4. Migrar pagos del cache a la nueva estructura"
echo "  5. Verificar la migración"
echo ""

# Confirmar
read -p "¿Deseas continuar con la migración? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Migración cancelada."
    exit 0
fi

# Paso 1: Subir SOLO los comandos de migración
echo ""
echo -e "${YELLOW}Paso 1: Subiendo comandos de migración al servidor...${NC}"

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/app/Console/Commands/ExtractCachedPayments.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/app/Console/Commands/

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/app/Console/Commands/MigrateCachedPayments.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/app/Console/Commands/

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/app/Console/Commands/VerifyPaymentMigration.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/app/Console/Commands/

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/app/Console/Commands/AnalyzeMigratedPayments.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/app/Console/Commands/

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Comandos subidos exitosamente!${NC}"
else
    echo -e "${RED}✗ Error al subir comandos${NC}"
    exit 1
fi

# Paso 2: Regenerar autoload y extraer cache
echo ""
echo -e "${YELLOW}Paso 2: Extrayendo datos del cache Redis...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDEXTRACT'
cd /var/www/controlcd-api

echo "→ Regenerando autoload..."
export COMPOSER_ALLOW_SUPERUSER=1
/opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/composer dump-autoload

echo "→ Verificando comandos disponibles..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan list | grep payments

echo ""
echo "→ Extrayendo datos del cache..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan payments:extract-cached

echo ""
echo "→ Creando backup del archivo JSON..."
BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
if ls storage/app/cached_payments_*.json 1> /dev/null 2>&1; then
    cp storage/app/cached_payments_*.json ~/backup_cache_staging_${BACKUP_DATE}.json
    echo "✓ Backup guardado en: ~/backup_cache_staging_${BACKUP_DATE}.json"
else
    echo "⚠ No se encontraron archivos de cache para respaldar"
fi

echo ""
echo "✓ Extracción completada!"
ENDEXTRACT

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error al extraer cache${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Cache extraído y respaldado!${NC}"

# Paso 3: Subir migraciones de base de datos
echo ""
echo -e "${YELLOW}Paso 3: Subiendo migraciones de base de datos...${NC}"

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/database/migrations/2025_12_03_002735_add_unapplied_amount_to_payments_table.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/database/migrations/

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/database/migrations/2025_12_04_213053_add_migrated_from_cache_to_payments_table.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/database/migrations/

echo -e "${GREEN}✓ Migraciones subidas!${NC}"

# Paso 4: Aplicar migraciones
echo ""
echo -e "${YELLOW}Paso 4: Aplicando migraciones de base de datos...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDMIGRATE'
cd /var/www/controlcd-api

echo "→ Ejecutando migraciones..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --force

echo ""
echo "→ Verificando columnas agregadas..."
mysql -u staging -p$(grep DB_PASSWORD .env | cut -d '=' -f2) staging_controlcd -e "DESCRIBE payments;" | grep -E "unapplied_amount|migrated_from_cache|migrated_at"

echo ""
echo "✓ Migraciones aplicadas!"
ENDMIGRATE

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error al aplicar migraciones${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Migraciones aplicadas exitosamente!${NC}"

# Paso 5: Ejecutar migración de pagos (dry-run primero)
echo ""
echo -e "${YELLOW}Paso 5: Probando migración (dry-run)...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDDRYRUN'
cd /var/www/controlcd-api

echo "→ Ejecutando migración en modo prueba..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan payments:migrate-cached --dry-run

echo ""
echo "✓ Dry-run completado!"
ENDDRYRUN

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error en dry-run${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Dry-run exitoso!${NC}"

# Confirmar migración real
echo ""
read -p "¿Ejecutar migración REAL? (s/n): " CONFIRM_MIGRATE
if [ "$CONFIRM_MIGRATE" != "s" ]; then
    echo -e "${YELLOW}Migración cancelada. Los comandos están listos para ejecutar manualmente.${NC}"
    echo ""
    echo "Para continuar manualmente, ejecuta en el servidor:"
    echo "  ssh -i $SSH_KEY $SERVER_USER@$SERVER_IP"
    echo "  cd $SERVER_PATH"
    echo "  /opt/cpanel/ea-php83/root/usr/bin/php artisan payments:migrate-cached"
    exit 0
fi

# Paso 6: Ejecutar migración real
echo ""
echo -e "${YELLOW}Paso 6: Ejecutando migración REAL...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDMIGRATE'
cd /var/www/controlcd-api

echo "→ Ejecutando migración real..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan payments:migrate-cached

echo ""
echo "✓ Migración completada!"
ENDMIGRATE

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error en migración${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Migración ejecutada exitosamente!${NC}"

# Paso 7: Verificar migración
echo ""
echo -e "${YELLOW}Paso 7: Verificando migración...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDVERIFY'
cd /var/www/controlcd-api

echo "→ Ejecutando verificación..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan payments:verify-migration

echo ""
echo "→ Generando análisis..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan payments:analyze-migrated --export

echo ""
echo "✓ Verificación completada!"
ENDVERIFY

if [ $? -ne 0 ]; then
    echo -e "${YELLOW}⚠ Verificación completada con advertencias${NC}"
else
    echo -e "${GREEN}✓ Verificación exitosa!${NC}"
fi

# Resumen final
echo ""
echo -e "${GREEN}=============================================="
echo "✓ Migración de Pagos Completada!"
echo -e "==============================================${NC}"
echo ""
echo -e "${YELLOW}Archivos generados en el servidor:${NC}"
echo "  - ~/backup_cache_staging_*.json (backup del cache)"
echo "  - storage/app/migrated_payments_analysis_*.csv (análisis)"
echo ""
echo -e "${YELLOW}Próximos pasos:${NC}"
echo "  1. Revisar el análisis generado"
echo "  2. Verificar que no hay errores en logs"
echo "  3. Hacer una prueba funcional (crear un pago nuevo)"
echo "  4. Si todo OK, desplegar el código completo con:"
echo "     ./deploy-to-server.sh"
echo ""
echo -e "${BLUE}Para descargar el análisis:${NC}"
echo "  scp -i $SSH_KEY $SERVER_USER@$SERVER_IP:$SERVER_PATH/storage/app/migrated_payments_analysis_*.csv ."
echo ""
