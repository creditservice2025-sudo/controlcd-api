#!/bin/bash
#
# Script para configurar dominios en el servidor
# ControCD - Domain Setup
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=================================="
echo "Configuración de Dominios"
echo "ControCD - Staging Environment"
echo -e "==================================${NC}"
echo ""

API_DOMAIN="staging-api.control-cd.com"
APP_DOMAIN="staging.control-cd.com"
BACKEND_PATH="/var/www/controlcd-api"
FRONTEND_PATH="/var/www/controlcd-app"

echo "Dominios a configurar:"
echo "  API Backend:  $API_DOMAIN"
echo "  Frontend App: $APP_DOMAIN"
echo ""

# Verificar que los dominios resuelven a este servidor
echo -e "${YELLOW}Verificando DNS...${NC}"
SERVER_IP=$(curl -s ifconfig.me)
API_IP=$(dig +short $API_DOMAIN | tail -n1)
APP_IP=$(dig +short $APP_DOMAIN | tail -n1)

echo "IP del servidor: $SERVER_IP"
echo "IP de $API_DOMAIN: $API_IP"
echo "IP de $APP_DOMAIN: $APP_IP"

if [ "$API_IP" != "$SERVER_IP" ]; then
    echo -e "${RED}⚠ ADVERTENCIA: El DNS de $API_DOMAIN no apunta a este servidor${NC}"
    echo "Asegúrate de configurar el registro A en tu proveedor de DNS"
fi

if [ "$APP_IP" != "$SERVER_IP" ]; then
    echo -e "${RED}⚠ ADVERTENCIA: El DNS de $APP_DOMAIN no apunta a este servidor${NC}"
    echo "Asegúrate de configurar el registro A en tu proveedor de DNS"
fi

echo ""
read -p "¿Deseas continuar? (s/n): " CONTINUE
if [ "$CONTINUE" != "s" ]; then
    echo "Configuración cancelada."
    exit 0
fi

# Copiar configuración de Nginx
echo ""
echo -e "${YELLOW}Configurando Nginx...${NC}"
sudo cp nginx-staging.conf /etc/nginx/conf.d/controlcd-staging.conf

# Eliminar configuración temporal por IP si existe
if [ -f /etc/nginx/conf.d/controlcd-ip.conf ]; then
    echo -e "${YELLOW}Eliminando configuración temporal por IP...${NC}"
    sudo rm /etc/nginx/conf.d/controlcd-ip.conf
fi

# Verificar configuración de Nginx
echo -e "${YELLOW}Verificando configuración de Nginx...${NC}"
sudo nginx -t

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error en la configuración de Nginx${NC}"
    exit 1
fi

# Reiniciar Nginx
echo -e "${YELLOW}Reiniciando Nginx...${NC}"
sudo systemctl restart nginx

# Limpiar cache de Laravel
echo ""
echo -e "${YELLOW}Limpiando cache de Laravel...${NC}"
cd $BACKEND_PATH
php artisan config:clear
php artisan cache:clear

echo ""
echo -e "${GREEN}✓ Dominios configurados correctamente!${NC}"
echo ""
echo -e "${BLUE}URLs de acceso:${NC}"
echo "  API Backend:  http://${API_DOMAIN}"
echo "  Frontend App: http://${APP_DOMAIN}"
echo ""
echo -e "${YELLOW}IMPORTANTE - Próximos pasos en tu MÁQUINA LOCAL:${NC}"
echo ""
echo "1. Actualizar .env del backend:"
echo "   cd /home/mario-d-az/git/ControCD-Backend"
echo "   Editar .env o crear .env.staging:"
echo "     APP_URL=http://${API_DOMAIN}"
echo "     APP_ENV=staging"
echo "   Subir con: ./deploy-to-server.sh"
echo ""
echo "2. Actualizar el frontend:"
echo "   cd /home/mario-d-az/git/ControCD-FrontEnd"
echo "   Editar .env.production:"
echo "     VITE_API_URL=http://${API_DOMAIN}/api"
echo "   npm run build"
echo "   rsync archivos al servidor"
echo ""
echo "3. Instalar certificados SSL (en el servidor):"
echo "   ./install-ssl.sh"
echo ""
echo "4. Después de SSL, actualizar URLs a HTTPS (en local):"
echo "   Backend: APP_URL=https://${API_DOMAIN}"
echo "   Frontend: VITE_API_URL=https://${API_DOMAIN}/api"
echo "   Volver a desplegar ambos"
