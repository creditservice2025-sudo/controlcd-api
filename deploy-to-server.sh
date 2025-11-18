#!/bin/bash
#
# Script de despliegue del Backend a servidor de producción
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
echo "ControCD Backend - Deploy"
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

# Ejecutar rsync
echo ""
# Verificar que existe .env
if [ ! -f "$LOCAL_PATH/.env" ]; then
    echo -e "${YELLOW}⚠ No se encontró archivo .env${NC}"
    echo "Se recomienda crear uno basado en .env.staging.example"
    read -p "¿Deseas continuar sin .env? (s/n): " CONTINUE_NO_ENV
    if [ "$CONTINUE_NO_ENV" != "s" ]; then
        exit 0
    fi
fi

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
  --exclude='context/' \
  $LOCAL_PATH/ \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Archivos sincronizados exitosamente!${NC}"
    
    # Ejecutar comandos post-deploy en el servidor
    echo ""
    echo -e "${YELLOW}Ejecutando comandos post-deploy en el servidor...${NC}"
    
    ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDSSH'
cd /var/www/controlcd-api

echo "→ Instalando dependencias de Composer..."
composer install --optimize-autoloader --no-dev --no-interaction

echo "→ Ejecutando migraciones..."
php artisan migrate --force

echo "→ Limpiando y cacheando configuraciones..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "→ Configurando permisos..."
chown -R nginx:nginx storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "→ Configurando SELinux contexts..."
chcon -R -t httpd_sys_rw_content_t storage
chcon -R -t httpd_sys_rw_content_t bootstrap/cache

echo ""
echo "✓ Post-deploy completado!"
ENDSSH

    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${GREEN}=================================="
        echo "✓ Despliegue completado exitosamente!"
        echo -e "==================================${NC}"
        echo ""
        echo "Verifica tu aplicación en: https://api.tudominio.com"
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
