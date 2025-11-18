#!/bin/bash
#
# Script para configurar acceso por IP temporal
# Antes de configurar el dominio
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

SERVER_IP="146.190.147.164"

echo -e "${BLUE}=================================="
echo "Configuración de Acceso por IP"
echo -e "==================================${NC}"
echo ""

# Copiar configuración de Nginx
echo -e "${YELLOW}Configurando Nginx para acceso por IP...${NC}"
sudo cp nginx-ip-temp.conf /etc/nginx/conf.d/controlcd-ip.conf

# Abrir puerto 8080 en firewall
echo -e "${YELLOW}Abriendo puerto 8080 en firewall...${NC}"
sudo firewall-cmd --permanent --add-port=8080/tcp
sudo firewall-cmd --reload

# Verificar y reiniciar Nginx
echo -e "${YELLOW}Reiniciando Nginx...${NC}"
sudo nginx -t
sudo systemctl restart nginx

echo ""
echo -e "${GREEN}✓ Configuración completada!${NC}"
echo ""
echo -e "${BLUE}Acceso a la aplicación:${NC}"
echo "  API Backend:  http://${SERVER_IP}/"
echo "  Frontend App: http://${SERVER_IP}:8080/"
echo ""
echo -e "${YELLOW}IMPORTANTE:${NC}"
echo "- Esta es una configuración temporal"
echo "- Cuando configures el dominio, ejecuta el script 04-setup-nginx.sh"
