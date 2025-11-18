#!/bin/bash
#
# Script de configuración de MySQL
# ControCD - Database Setup
#

set -e

echo "=================================="
echo "Configuración de Base de Datos"
echo "MySQL 8.0 - ControCD"
echo "=================================="
echo ""

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Solicitar información
read -p "Nombre de la base de datos [controlcd_db]: " DB_NAME
DB_NAME=${DB_NAME:-controlcd_db}

read -p "Usuario de la base de datos [controlcd_user]: " DB_USER
DB_USER=${DB_USER:-controlcd_user}

read -sp "Contraseña para el usuario de la base de datos: " DB_PASS
echo ""

if [ -z "$DB_PASS" ]; then
    echo -e "${RED}Error: La contraseña no puede estar vacía${NC}"
    exit 1
fi

read -sp "Contraseña de root de MySQL: " MYSQL_ROOT_PASS
echo ""

if [ -z "$MYSQL_ROOT_PASS" ]; then
    echo -e "${YELLOW}Intentando sin contraseña de root...${NC}"
    MYSQL_CMD="sudo mysql"
else
    MYSQL_CMD="mysql -u root -p${MYSQL_ROOT_PASS}"
fi

# Crear base de datos y usuario
echo -e "${YELLOW}Creando base de datos y usuario...${NC}"

$MYSQL_CMD <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Base de datos configurada correctamente!${NC}"
    
    # Guardar configuración en archivo
    cat > /tmp/db_config.txt <<EOF
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
EOF
    
    echo ""
    echo "Configuración guardada en: /tmp/db_config.txt"
    echo "Esta información será usada en el siguiente script."
else
    echo -e "${RED}✗ Error al configurar la base de datos${NC}"
    exit 1
fi

echo ""
echo "Siguiente paso: Ejecutar 03-setup-backend.sh"
