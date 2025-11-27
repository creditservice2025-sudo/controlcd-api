#!/bin/bash
#
# Script para configurar MySQL para acceso remoto en PRODUCCIÓN
# ControCD - MySQL Remote Access Setup
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=============================================="
echo "Configurar MySQL para Acceso Remoto"
echo "PRODUCCIÓN - 128.199.1.223"
echo -e "==============================================${NC}"
echo ""

echo -e "${YELLOW}Este script va a:${NC}"
echo "1. Configurar bind-address = 0.0.0.0 en MySQL"
echo "2. Verificar/crear usuario controlcd_admin con acceso desde cualquier IP"
echo "3. Reiniciar MySQL"
echo "4. Verificar que el puerto 3306 esté abierto"
echo ""

read -p "¿Deseas continuar? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Operación cancelada."
    exit 0
fi

echo ""
echo -e "${YELLOW}Conectando al servidor...${NC}"

ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223 << 'ENDSSH'

echo "=== PASO 1: Configurando MySQL para aceptar conexiones remotas ==="

# Buscar archivo de configuración de MySQL
CONFIG_FILE=""
if [ -f /etc/my.cnf ]; then
    CONFIG_FILE="/etc/my.cnf"
elif [ -f /etc/mysql/mysql.conf.d/mysqld.cnf ]; then
    CONFIG_FILE="/etc/mysql/mysql.conf.d/mysqld.cnf"
elif [ -f /etc/my.cnf.d/mysql-server.cnf ]; then
    CONFIG_FILE="/etc/my.cnf.d/mysql-server.cnf"
fi

if [ -z "$CONFIG_FILE" ]; then
    echo "No se encontró archivo de configuración, creando /etc/my.cnf.d/remote-access.cnf"
    mkdir -p /etc/my.cnf.d
    cat > /etc/my.cnf.d/remote-access.cnf <<'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF
    echo "✓ Archivo de configuración creado"
else
    echo "Archivo de configuración encontrado: $CONFIG_FILE"

    # Hacer backup
    cp "$CONFIG_FILE" "${CONFIG_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
    echo "✓ Backup creado"

    # Verificar si ya existe bind-address
    if grep -q "^bind-address" "$CONFIG_FILE"; then
        echo "Actualizando bind-address existente..."
        sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' "$CONFIG_FILE"
    else
        echo "Agregando bind-address..."
        # Agregar en la sección [mysqld]
        if grep -q "^\[mysqld\]" "$CONFIG_FILE"; then
            sed -i '/^\[mysqld\]/a bind-address = 0.0.0.0' "$CONFIG_FILE"
        else
            echo -e "\n[mysqld]\nbind-address = 0.0.0.0" >> "$CONFIG_FILE"
        fi
    fi
    echo "✓ bind-address configurado a 0.0.0.0"
fi

echo ""
echo "=== PASO 2: Configurando usuario MySQL ==="

# Pedir contraseña de root de MySQL
read -s -p "Ingresa la contraseña de root de MySQL: " MYSQL_ROOT_PASS
echo

# Crear/verificar usuario con acceso remoto
mysql -u root -p"$MYSQL_ROOT_PASS" <<'EOFMYSQL'
-- Eliminar usuario si existe (para recrearlo con permisos correctos)
DROP USER IF EXISTS 'controlcd_admin'@'%';

-- Crear usuario con acceso desde cualquier IP
CREATE USER 'controlcd_admin'@'%' IDENTIFIED BY '55a30c6c-2473-4279-823a-261f2cce92ee';

-- Otorgar permisos completos sobre controlcd_prod
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_admin'@'%';

-- Aplicar cambios
FLUSH PRIVILEGES;

-- Mostrar usuario creado
SELECT User, Host, plugin FROM mysql.user WHERE User = 'controlcd_admin';

EOFMYSQL

echo "✓ Usuario MySQL configurado"

echo ""
echo "=== PASO 3: Reiniciando MySQL ==="
systemctl restart mysqld || systemctl restart mysql
sleep 2
systemctl status mysqld --no-pager | head -10 || systemctl status mysql --no-pager | head -10

echo ""
echo "=== PASO 4: Verificando que MySQL esté escuchando en 0.0.0.0 ==="
ss -tlnp | grep 3306

echo ""
echo "=== PASO 5: Verificando firewall ==="
if command -v firewall-cmd >/dev/null 2>&1; then
    echo "Firewalld detectado, verificando puerto 3306..."
    if ! firewall-cmd --list-ports | grep -q 3306; then
        echo "Agregando puerto 3306 al firewall..."
        firewall-cmd --permanent --add-port=3306/tcp
        firewall-cmd --reload
        echo "✓ Puerto 3306 agregado al firewall"
    else
        echo "✓ Puerto 3306 ya está abierto en firewall"
    fi
elif command -v ufw >/dev/null 2>&1; then
    echo "UFW detectado, verificando puerto 3306..."
    ufw allow 3306/tcp
    echo "✓ Puerto 3306 configurado en UFW"
else
    echo "⚠ No se detectó firewall (firewalld/ufw)"
    echo "  Verificar manualmente que el puerto 3306 esté abierto"
fi

echo ""
echo "✓ Configuración completada!"

ENDSSH

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}=============================================="
    echo "✓ MySQL configurado para acceso remoto"
    echo -e "==============================================${NC}"
    echo ""
    echo -e "${YELLOW}Credenciales de conexión:${NC}"
    echo "  Host: 128.199.1.223"
    echo "  Puerto: 3306"
    echo "  Usuario: controlcd_admin"
    echo "  Contraseña: 55a30c6c-2473-4279-823a-261f2cce92ee"
    echo "  Base de datos: controlcd_prod"
    echo ""
    echo -e "${YELLOW}Probar conexión desde tu máquina:${NC}"
    echo "mysql -h 128.199.1.223 -u controlcd_admin -p'55a30c6c-2473-4279-823a-261f2cce92ee' controlcd_prod"
    echo ""
else
    echo ""
    echo -e "${RED}✗ Error en la configuración${NC}"
    exit 1
fi
