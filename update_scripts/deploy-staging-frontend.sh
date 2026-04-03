#!/bin/bash
#
# Script de despliegue del Frontend a servidor
# ControCD Frontend - Deploy Script
#

set -e

# Configuración
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
SERVER_USER="root"
SERVER_IP="146.190.147.164"
SERVER_PATH="/var/www/controlcd-app"
LOCAL_PATH="/home/mario-d-az/git/ControCD-FrontEnd"

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=================================="
echo "ControCD Frontend - Deploy"
echo -e "==================================${NC}"
echo ""

# Verificar que estamos en el directorio correcto
if [ ! -f "$LOCAL_PATH/package.json" ]; then
    echo -e "${RED}Error: No se encuentra package.json en $LOCAL_PATH${NC}"
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

# Seleccionar ambiente
echo "¿Qué ambiente deseas desplegar?"
echo "1) Staging (staging-api.control-cd.com)"
echo "2) Production"
echo ""
read -p "Selecciona una opción (1-2): " ENV_OPTION

case $ENV_OPTION in
    1)
        ENV="staging"
        BUILD_CMD="npx cross-env QENV=staging quasar build"
        echo -e "${YELLOW}Desplegando a STAGING...${NC}"
        ;;
    2)
        ENV="production"
        BUILD_CMD="npm run prodBuild"
        echo -e "${YELLOW}Desplegando a PRODUCTION...${NC}"
        ;;
    *)
        echo -e "${RED}Opción inválida${NC}"
        exit 1
        ;;
esac

echo ""
read -p "¿Deseas continuar con el despliegue? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Despliegue cancelado."
    exit 0
fi

# Navegar al directorio
cd $LOCAL_PATH

# Instalar dependencias
echo ""
echo -e "${YELLOW}Instalando dependencias...${NC}"
npm install

# Compilar proyecto
echo ""
echo -e "${YELLOW}Compilando proyecto...${NC}"
$BUILD_CMD

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error al compilar el proyecto${NC}"
    exit 1
fi

# Verificar que existe el directorio dist/spa
if [ ! -d "$LOCAL_PATH/dist/spa" ]; then
    echo -e "${RED}✗ No se encontró el directorio dist/spa${NC}"
    exit 1
fi

# Crear backup en el servidor
echo ""
echo -e "${YELLOW}Creando backup en el servidor...${NC}"

BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="_backup_${BACKUP_DATE}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << ENDBACKUP
if [ -d "$SERVER_PATH" ]; then
    echo "→ Creando backup: $BACKUP_NAME"
    cd /var/www
    cp -r controlcd-app "$BACKUP_NAME"
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

# Crear directorio en el servidor si no existe
echo ""
echo -e "${YELLOW}Preparando servidor...${NC}"
ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP "sudo mkdir -p $SERVER_PATH && sudo chown -R nginx:nginx $SERVER_PATH"

# Sincronizar archivos
echo ""
echo -e "${YELLOW}Sincronizando archivos al servidor...${NC}"

rsync -avzP --delete \
  -e "ssh -i $SSH_KEY" \
  $LOCAL_PATH/dist/spa/ \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Archivos sincronizados exitosamente!${NC}"
    
    # Limpiar caché de Nginx
    echo ""
    echo -e "${YELLOW}Limpiando caché del servidor...${NC}"
ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDCACHE'
# Limpiar caché de Nginx si existe
if [ -d "/var/cache/nginx" ]; then
    sudo rm -rf /var/cache/nginx/*
    echo "✓ Caché de Nginx limpiado"
fi

# Reiniciar Nginx para forzar recarga
sudo systemctl reload nginx
echo "✓ Nginx recargado"
ENDCACHE
    
    # Configurar permisos en el servidor
    echo ""
    echo -e "${YELLOW}Configurando permisos...${NC}"
ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDSSH'
cd /var/www/controlcd-app

# Configurar permisos
sudo chown -R nginx:nginx /var/www/controlcd-app
sudo chmod -R 755 /var/www/controlcd-app

# Configurar SELinux
sudo chcon -R -t httpd_sys_content_t /var/www/controlcd-app

echo "✓ Permisos configurados!"
ENDSSH

    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${GREEN}=================================="
        echo "✓ Despliegue completado exitosamente!"
        echo -e "==================================${NC}"
        echo ""
        if [ "$ENV" = "staging" ]; then
            echo "Verifica tu aplicación en: https://staging.control-cd.com"
        else
            echo "Verifica tu aplicación en tu dominio de producción"
        fi
    else
        echo ""
        echo -e "${RED}✗ Error al configurar permisos${NC}"
        exit 1
    fi
else
    echo ""
    echo -e "${RED}✗ Error al sincronizar archivos${NC}"
    exit 1
fi

