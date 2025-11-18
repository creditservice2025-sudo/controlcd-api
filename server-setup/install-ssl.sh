#!/bin/bash
#
# Script para instalar certificados SSL con Let's Encrypt
# ControCD - SSL Setup
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=================================="
echo "Instalación de Certificados SSL"
echo "Let's Encrypt - Certbot"
echo -e "==================================${NC}"
echo ""

API_DOMAIN="staging-api.control-cd.com"
APP_DOMAIN="staging.control-cd.com"
EMAIL="admin@control-cd.com"

echo "Dominios a certificar:"
echo "  - $API_DOMAIN"
echo "  - $APP_DOMAIN"
echo ""

read -p "Email para notificaciones de Let's Encrypt [$EMAIL]: " USER_EMAIL
EMAIL=${USER_EMAIL:-$EMAIL}

echo ""
echo -e "${YELLOW}[1/3] Instalando Certbot...${NC}"
sudo dnf install certbot python3-certbot-nginx -y

echo ""
echo -e "${YELLOW}[2/3] Obteniendo certificados SSL...${NC}"
sudo certbot --nginx \
    -d $API_DOMAIN \
    -d $APP_DOMAIN \
    --non-interactive \
    --agree-tos \
    --email $EMAIL \
    --redirect

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Certificados SSL instalados correctamente!${NC}"
    
    # Actualizar .env del backend a HTTPS
    echo ""
    echo -e "${YELLOW}[3/3] Actualizando configuración a HTTPS...${NC}"
    cd /var/www/controlcd-api
    sudo sed -i "s|^APP_URL=.*|APP_URL=https://${API_DOMAIN}|" .env
    php artisan config:clear
    php artisan config:cache
    
    echo ""
    echo -e "${GREEN}=================================="
    echo "✓ SSL Configurado Exitosamente!"
    echo -e "==================================${NC}"
    echo ""
    echo -e "${BLUE}URLs seguras:${NC}"
    echo "  API Backend:  https://${API_DOMAIN}"
    echo "  Frontend App: https://${APP_DOMAIN}"
    echo ""
    echo -e "${YELLOW}IMPORTANTE:${NC}"
    echo "Actualiza el frontend con la URL HTTPS:"
    echo "  VITE_API_URL=https://${API_DOMAIN}/api"
    echo ""
    echo "Los certificados se renovarán automáticamente."
    echo "Puedes verificar con: sudo certbot renew --dry-run"
else
    echo ""
    echo -e "${RED}✗ Error al obtener certificados SSL${NC}"
    echo ""
    echo "Verifica que:"
    echo "1. Los dominios apunten correctamente a este servidor"
    echo "2. Los puertos 80 y 443 estén abiertos en el firewall"
    echo "3. Nginx esté funcionando correctamente"
    exit 1
fi
