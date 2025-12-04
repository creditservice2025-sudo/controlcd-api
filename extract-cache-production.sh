#!/bin/bash
#
# Script de Extracción de Pagos en Cache - PRODUCCIÓN
# ControCD Backend - Payment Cache Extraction (PRODUCTION)
#
# Este script SOLO extrae y respalda los datos del cache
# NO hace la migración completa
#

set -e

# Configuración PRODUCCIÓN
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
SERVER_USER="root"
SERVER_IP="128.199.1.223"
SERVER_PATH="/var/www/controlcd-api"
LOCAL_PATH="/home/mario-d-az/git/ControCD-Backend"

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${RED}=============================================="
echo "ControCD - Extracción de Cache"
echo "⚠️  SERVIDOR DE PRODUCCIÓN ⚠️"
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
echo "  Servidor: $SERVER_USER@$SERVER_IP (PRODUCCIÓN)"
echo "  Ruta remota: $SERVER_PATH"
echo "  Ruta local: $LOCAL_PATH"
echo ""

echo -e "${YELLOW}Este script realizará:${NC}"
echo "  1. Subir comando de extracción al servidor"
echo "  2. Extraer datos del cache Redis"
echo "  3. Crear backup JSON"
echo "  4. Descargar el backup a tu máquina local"
echo ""
echo -e "${RED}⚠️  IMPORTANTE: Este es el servidor de PRODUCCIÓN${NC}"
echo -e "${RED}⚠️  Solo se extraerá el cache, NO se hará migración${NC}"
echo ""

# Confirmar
read -p "¿Deseas continuar con la extracción? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Extracción cancelada."
    exit 0
fi

# Paso 1: Subir SOLO el comando de extracción
echo ""
echo -e "${YELLOW}Paso 1: Subiendo comando de extracción al servidor...${NC}"

rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/app/Console/Commands/ExtractCachedPayments.php \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/app/Console/Commands/

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Comando subido exitosamente!${NC}"
else
    echo -e "${RED}✗ Error al subir comando${NC}"
    exit 1
fi

# Paso 2: Regenerar autoload y extraer cache
echo ""
echo -e "${YELLOW}Paso 2: Extrayendo datos del cache Redis...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDEXTRACT'
cd /var/www/controlcd-api

echo "→ Regenerando autoload..."
export COMPOSER_ALLOW_SUPERUSER=1
composer dump-autoload

echo "→ Verificando comando disponible..."
php artisan list | grep "payments:extract-cached"

echo ""
echo "→ Extrayendo datos del cache..."
php artisan payments:extract-cached

echo ""
echo "→ Creando backup del archivo JSON..."
BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
if ls storage/app/cached_payments_*.json 1> /dev/null 2>&1; then
    # Copiar a home para fácil descarga
    cp storage/app/cached_payments_*.json ~/backup_cache_production_${BACKUP_DATE}.json
    # También dejar una copia en storage
    cp storage/app/cached_payments_*.json storage/app/backup_cache_production_${BACKUP_DATE}.json
    echo "✓ Backup guardado en:"
    echo "  - ~/backup_cache_production_${BACKUP_DATE}.json"
    echo "  - storage/app/backup_cache_production_${BACKUP_DATE}.json"

    # Mostrar resumen
    echo ""
    echo "→ Resumen del archivo:"
    ls -lh ~/backup_cache_production_${BACKUP_DATE}.json
else
    echo "⚠ No se encontraron archivos de cache para respaldar"
    echo "Esto puede significar que no hay abonos pendientes en cache"
fi

echo ""
echo "✓ Extracción completada!"
ENDEXTRACT

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error al extraer cache${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Cache extraído y respaldado en el servidor!${NC}"

# Paso 3: Descargar el backup a la máquina local
echo ""
echo -e "${YELLOW}Paso 3: Descargando backup a máquina local...${NC}"

# Crear directorio local para backups si no existe
mkdir -p ~/backups_controlcd_production

# Descargar el archivo
scp -i "$SSH_KEY" \
  "$SERVER_USER@$SERVER_IP:~/backup_cache_production_*.json" \
  ~/backups_controlcd_production/

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Backup descargado exitosamente!${NC}"
    echo ""
    echo "Archivo guardado en: ~/backups_controlcd_production/"
    ls -lh ~/backups_controlcd_production/backup_cache_production_*.json 2>/dev/null | tail -1
else
    echo -e "${YELLOW}⚠ No se pudo descargar automáticamente${NC}"
    echo "Puedes descargarlo manualmente con:"
    echo "  scp -i $SSH_KEY $SERVER_USER@$SERVER_IP:~/backup_cache_production_*.json ."
fi

# Resumen final
echo ""
echo -e "${GREEN}=============================================="
echo "✓ Extracción Completada!"
echo -e "==============================================${NC}"
echo ""
echo -e "${YELLOW}Archivos generados:${NC}"
echo "  En el servidor:"
echo "    - ~/backup_cache_production_*.json"
echo "    - storage/app/backup_cache_production_*.json"
echo ""
echo "  En tu máquina local:"
echo "    - ~/backups_controlcd_production/backup_cache_production_*.json"
echo ""
echo -e "${YELLOW}Próximos pasos:${NC}"
echo "  1. Revisar el archivo JSON descargado"
echo "  2. Verificar cuántos pagos hay en cache"
echo "  3. Si hay pagos, planificar la migración completa"
echo ""
echo -e "${BLUE}Para ver el contenido del JSON:${NC}"
echo "  cat ~/backups_controlcd_production/backup_cache_production_*.json | jq ."
echo ""
echo -e "${BLUE}Para contar pagos en cache:${NC}"
echo "  cat ~/backups_controlcd_production/backup_cache_production_*.json | jq 'length'"
echo ""
