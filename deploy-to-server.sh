#!/bin/bash
#
# Script de despliegue del Backend a servidor de Staging
# ControCD Backend - Deploy Script
#

set -e

# Configuración
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

echo -e "${BLUE}=================================="
echo "ControCD Backend - STAGING- Deploy"
echo -e "==================================${NC}"
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

# Confirmar
read -p "¿Deseas continuar con el despliegue? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Despliegue cancelado."
    exit 0
fi

# Nota: El archivo .env NO se sube al servidor para preservar la configuración del servidor
echo ""
echo -e "${YELLOW}Nota: El archivo .env del servidor NO será modificado${NC}"

# Crear backup en el servidor
echo ""
echo -e "${YELLOW}Creando backup en el servidor...${NC}"

BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="_backup_${BACKUP_DATE}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << ENDBACKUP
if [ -d "$SERVER_PATH" ]; then
    echo "→ Creando backup: $BACKUP_NAME"
    cd /var/www
    cp -r controlcd-api "$BACKUP_NAME"
    echo "✓ Backup creado en: /var/www/$BACKUP_NAME"
else
    echo "⚠ El directorio $SERVER_PATH no existe, saltando backup"
    exit 1
fi
ENDBACKUP

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Backup creado exitosamente en el servidor!${NC}"
else
    echo -e "${YELLOW}⚠ Error al crear backup (continuando con deploy)${NC}"
fi

echo ""
echo -e "${YELLOW}Sincronizando archivos...${NC}"

rsync -avzP --delete \
  -e "ssh -i $SSH_KEY" \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitlab-ci.yml' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/app/public/*' \
  --exclude='storage/oauth-*.key' \
  --exclude='public/images' \
  --exclude='.env' \
  --exclude='.env.example' \
  --exclude='.env.staging.example' \
  --exclude='.database-data/' \
  --exclude='docker-compose.yml' \
  --exclude='Dockerfile' \
  --exclude='.docker/' \
  --exclude='phpunit.xml' \
  --exclude='tests/' \
  --exclude='.phpunit.result.cache' \
  --exclude='*.log' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='server-setup/' \
  --exclude='deploy-to-server.sh' \
  --exclude='*.sh' \
  --exclude='*.md' \
  --exclude='*.sql' \
  --exclude='context/' \
  $LOCAL_PATH/ \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Archivos sincronizados exitosamente!${NC}"

    # Ejecutar comandos post-deploy en el servidor
    echo ""
    echo -e "${YELLOW}Ejecutando comandos post-deploy en el servidor...${NC}"

    # Preguntar si se desea sobrescribir .env (por seguridad)
    echo ""
    read -p "¿Deseas SOBRESCRIBIR el archivo .env en el servidor con .env.staging.example? (s/N): " OVERWRITE_ENV
    OVERWRITE_ENV=${OVERWRITE_ENV:-n}

    ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP bash -s "$OVERWRITE_ENV" << 'ENDSSH'
OVERWRITE_ENV=$1

cd /var/www/controlcd-api

echo "→ Arreglando ownership de git..."
git config --global --add safe.directory /var/www/controlcd-api 2>/dev/null || true

echo "→ Configurando archivo .env para staging..."
if [[ "$OVERWRITE_ENV" == "s" || "$OVERWRITE_ENV" == "S" ]]; then
    if [ -f .env.staging.example ]; then
        cp .env.staging.example .env
        echo "  ✓ .env SOBRESCRITO desde .env.staging.example"
    else
        echo "  ⚠ No se encontró .env.staging.example"
    fi
else
    echo "  ℹ Saltando actualización de .env (conservando actual)"
fi

echo "→ Verificando e instalando extensiones PHP necesarias..."
# Instalar extensiones PHP 8.3 requeridas si no están instaladas
yum list installed ea-php83-php-fileinfo 2>/dev/null || yum install -y ea-php83-php-fileinfo
yum list installed ea-php83-php-iconv 2>/dev/null || yum install -y ea-php83-php-iconv

echo "→ Instalando dependencias de Composer con PHP 8.3..."
export COMPOSER_ALLOW_SUPERUSER=1
/opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/composer install --optimize-autoloader --no-dev --no-interaction

echo "→ Ejecutando migraciones..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --force

echo "→ Limpiando cache..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan config:clear
/opt/cpanel/ea-php83/root/usr/bin/php artisan cache:clear
/opt/cpanel/ea-php83/root/usr/bin/php artisan route:clear
/opt/cpanel/ea-php83/root/usr/bin/php artisan view:clear

echo "→ Verificando configuración de Laravel..."
# Generar APP_KEY si no existe
if ! grep -q "APP_KEY=base64:" .env; then
    echo "  → Generando APP_KEY..."
    /opt/cpanel/ea-php83/root/usr/bin/php artisan key:generate
fi

# Generar keys de Passport si no existen
if [ ! -f storage/oauth-private.key ]; then
    echo "  → Generando Passport keys..."
    /opt/cpanel/ea-php83/root/usr/bin/php artisan passport:keys --force
fi

echo "→ Configurando permisos (CRÍTICO para evitar errores 500)..."
# Permisos generales de storage y cache
chown -R staging:staging storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chmod -R 664 storage/logs/*.log 2>/dev/null || true
chmod 775 storage/logs

# IMPORTANTE: Corregir permisos de OAuth keys (siempre después de rsync)
echo "  → Corrigiendo permisos de OAuth Passport keys..."
if [ -f storage/oauth-private.key ]; then
    chown staging:staging storage/oauth-private.key storage/oauth-public.key
    chmod 600 storage/oauth-private.key
    chmod 644 storage/oauth-public.key
    echo "  ✓ OAuth keys: permisos corregidos"
else
    echo "  ⚠ OAuth keys no encontradas, ejecuta: php artisan passport:keys"
fi

echo "→ Cacheando configuraciones..."
/opt/cpanel/ea-php83/root/usr/bin/php artisan config:cache
/opt/cpanel/ea-php83/root/usr/bin/php artisan route:cache
/opt/cpanel/ea-php83/root/usr/bin/php artisan view:cache

echo "→ Configurando SELinux contexts..."
chcon -R -t httpd_sys_rw_content_t storage 2>/dev/null || true
chcon -R -t httpd_sys_rw_content_t bootstrap/cache 2>/dev/null || true

echo "→ Reiniciando PHP-FPM..."
systemctl restart ea-php83-php-fpm

echo ""
echo "✓ Post-deploy completado!"
ENDSSH

    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${GREEN}=================================="
        echo "✓ Despliegue completado exitosamente!"
        echo -e "==================================${NC}"
        echo ""
        echo "Verifica tu aplicación en: https://staging-api.control-cd.com"
        echo ""
        echo -e "${YELLOW}Nota: Permisos corregidos automáticamente y PHP-FPM reiniciado${NC}"
    else
        echo ""
        echo -e "${RED}✗ Error en comandos post-deploy${NC}"
        exit 1
    fi
else
    echo ""
    echo -e "${RED}✗ Error al sincronizar archivos${NC}"
    exit 1
fi
