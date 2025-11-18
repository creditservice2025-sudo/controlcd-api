#!/bin/bash
#
# Script completo para solucionar CORS desde la máquina local
# ControCD - Fix CORS Completo
#

set -e

SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
SERVER="root@146.190.147.164"
BACKEND_PATH="/home/mario-d-az/git/ControCD-Backend"

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=================================="
echo "Fix CORS - Proceso Completo"
echo "ControCD"
echo -e "==================================${NC}"
echo ""

# Paso 1: Desplegar backend
echo -e "${YELLOW}[1/3] Desplegando backend con configuración de CORS...${NC}"
cd $BACKEND_PATH
./deploy-to-server.sh

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error al desplegar backend${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}✓ Backend desplegado${NC}"

# Paso 2: Subir script fix-cors.sh si no existe
echo ""
echo -e "${YELLOW}[2/3] Subiendo script fix-cors.sh al servidor...${NC}"
scp -i $SSH_KEY $BACKEND_PATH/server-setup/fix-cors.sh $SERVER:~/server-setup/

# Paso 3: Ejecutar fix-cors.sh en el servidor
echo ""
echo -e "${YELLOW}[3/3] Aplicando configuración de CORS en Nginx...${NC}"
ssh -i $SSH_KEY $SERVER << 'ENDSSH'
cd ~/server-setup
chmod +x fix-cors.sh
./fix-cors.sh
ENDSSH

echo ""
echo -e "${GREEN}=================================="
echo "✓ CORS Configurado Exitosamente!"
echo -e "==================================${NC}"
echo ""
echo -e "${BLUE}Próximos pasos:${NC}"
echo "1. Limpia la cache del navegador: Ctrl + Shift + R"
echo "2. Intenta hacer login en: https://staging.control-cd.com"
echo ""
echo -e "${YELLOW}Para verificar:${NC}"
echo "curl -I -X OPTIONS \\"
echo "  -H \"Origin: https://staging.control-cd.com\" \\"
echo "  -H \"Access-Control-Request-Method: POST\" \\"
echo "  https://staging-api.control-cd.com/api/login"
