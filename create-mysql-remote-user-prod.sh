#!/bin/bash
#
# Script para crear usuario de MySQL con acceso remoto en PRODUCCIÓN
# ControCD - MySQL Remote User Setup
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}==========================================="
echo "Crear Usuario MySQL Remoto - PRODUCCIÓN"
echo -e "===========================================${NC}"
echo ""

# Configuración del nuevo usuario
NEW_USER="controlcd_admin"
DB_NAME="controlcd_prod"

# Generar contraseña segura (puedes cambiarla después)
PASSWORD="55a30c6c-2473-4279-823a-261f2cce92ee"

echo -e "${YELLOW}Usuario a crear:${NC}"
echo "  Usuario: $NEW_USER"
echo "  Base de datos: $DB_NAME"
echo "  Acceso: Remoto (desde cualquier IP)"
echo "  Permisos: SELECT, INSERT, UPDATE, DELETE (NO DROP/CREATE)"
echo ""
echo -e "${RED}⚠️  ADVERTENCIA: Este script creará un usuario con acceso remoto${NC}"
echo ""

read -p "¿Deseas continuar? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Operación cancelada."
    exit 0
fi

# SSH al servidor y ejecutar comandos MySQL
echo ""
echo -e "${YELLOW}Conectando al servidor de producción...${NC}"

ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223 << 'ENDSSH'
# Comandos a ejecutar en el servidor

echo "Conectando a MySQL..."

mysql -u root -p << 'EOFMYSQL'
-- Crear usuario con acceso desde cualquier IP (%)
CREATE USER IF NOT EXISTS 'controlcd_admin'@'%' IDENTIFIED BY 'Admin2025!ControCD#Remote';

-- Otorgar permisos sobre la base de datos controlcd_prod
-- Solo permisos de lectura/escritura, NO DROP ni CREATE DATABASE
GRANT SELECT, INSERT, UPDATE, DELETE ON controlcd_prod.* TO 'controlcd_admin'@'%';

-- Aplicar cambios
FLUSH PRIVILEGES;

-- Mostrar usuarios creados
SELECT User, Host FROM mysql.user WHERE User = 'controlcd_admin';

-- Mostrar permisos del usuario
SHOW GRANTS FOR 'controlcd_admin'@'%';

EOFMYSQL

echo ""
echo "✓ Usuario MySQL creado exitosamente"

ENDSSH

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}==========================================="
    echo "✓ Usuario remoto creado exitosamente"
    echo -e "===========================================${NC}"
    echo ""
    echo "Detalles de conexión:"
    echo "  Host: 128.199.1.223"
    echo "  Puerto: 3306"
    echo "  Usuario: $NEW_USER"
    echo "  Contraseña: $PASSWORD"
    echo "  Base de datos: $DB_NAME"
    echo ""
    echo -e "${YELLOW}Comando de prueba desde tu máquina local:${NC}"
    echo "mysql -h 128.199.1.223 -u $NEW_USER -p$PASSWORD $DB_NAME"
    echo ""
    echo -e "${RED}⚠️  IMPORTANTE: Guarda estas credenciales de forma segura${NC}"
    echo -e "${RED}⚠️  Asegúrate de que el firewall del servidor permita conexiones MySQL (puerto 3306)${NC}"
else
    echo ""
    echo -e "${RED}✗ Error al crear el usuario${NC}"
    exit 1
fi
