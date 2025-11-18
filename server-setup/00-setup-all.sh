#!/bin/bash
#
# Script maestro de configuración completa
# ControCD - Complete Server Setup
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

clear
echo -e "${BLUE}"
cat << "EOF"
  ____            _             _    ____ ____  
 / ___|___  _ __ | |_ _ __ ___ | |  / ___|  _ \ 
| |   / _ \| '_ \| __| '__/ _ \| | | |   | | | |
| |__| (_) | | | | |_| | | (_) | | | |___| |_| |
 \____\___/|_| |_|\__|_|  \___/|_|  \____|____/ 
                                                 
    Server Setup - AlmaLinux
EOF
echo -e "${NC}"
echo ""

echo "Este script configurará automáticamente todo el servidor."
echo "Asegúrate de tener:"
echo "  - Permisos sudo"
echo "  - Conexión a internet"
echo "  - Los dominios configurados"
echo ""
read -p "¿Deseas continuar? (s/n): " CONTINUE

if [ "$CONTINUE" != "s" ]; then
    echo "Instalación cancelada."
    exit 0
fi

# Verificar que estamos en el directorio correcto
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

echo ""
echo -e "${YELLOW}======================================"
echo "Iniciando configuración del servidor"
echo -e "======================================${NC}"
echo ""

# Paso 1: Instalar dependencias
echo -e "${BLUE}[Paso 1/5] Instalando dependencias...${NC}"
chmod +x 01-install-dependencies.sh
./01-install-dependencies.sh

echo ""
read -p "Presiona Enter para continuar con el siguiente paso..."

# Paso 2: Configurar base de datos
echo ""
echo -e "${BLUE}[Paso 2/5] Configurando base de datos...${NC}"
chmod +x 02-setup-database.sh
./02-setup-database.sh

echo ""
read -p "Presiona Enter para continuar con el siguiente paso..."

# Paso 3: Configurar backend
echo ""
echo -e "${BLUE}[Paso 3/5] Configurando backend...${NC}"
chmod +x 03-setup-backend.sh
./03-setup-backend.sh

echo ""
read -p "Presiona Enter para continuar con el siguiente paso..."

# Paso 4: Configurar Nginx
echo ""
echo -e "${BLUE}[Paso 4/5] Configurando Nginx...${NC}"
chmod +x 04-setup-nginx.sh
./04-setup-nginx.sh

echo ""
read -p "Presiona Enter para continuar con el siguiente paso..."

# Paso 5: Configurar frontend
echo ""
echo -e "${BLUE}[Paso 5/5] Configurando frontend...${NC}"
chmod +x 05-deploy-frontend.sh
./05-deploy-frontend.sh

echo ""
echo -e "${GREEN}"
cat << "EOF"
 ____                      _      _       _ 
/ ___|  _____   _____ _ __| |    (_)___  | |
\___ \ / _ \ \ / / _ \ '__| |    | / __| | |
 ___) |  __/\ V /  __/ |  | |___ | \__ \ |_|
|____/ \___| \_/ \___|_|  |_____||_|___/ (_)
                                            
EOF
echo -e "${NC}"

echo "✓ Configuración del servidor completada!"
echo ""
echo "Revisa los logs y asegúrate de que todo esté funcionando."
echo "No olvides configurar SSL con certbot."
