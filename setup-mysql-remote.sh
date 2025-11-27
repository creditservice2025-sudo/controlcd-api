#!/bin/bash
#
# Script SIMPLE para habilitar acceso remoto a MySQL en producción
#

echo "=== Configurando MySQL para acceso remoto en PRODUCCIÓN ==="
echo ""
echo "Servidor: 128.199.1.223"
echo "Usuario a crear: controlcd_admin"
echo "Contraseña: 55a30c6c-2473-4279-823a-261f2cce92ee"
echo ""

read -p "¿Continuar? (s/n): " confirm
if [ "$confirm" != "s" ]; then
    exit 0
fi

# Conectar al servidor y ejecutar TODO
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223 'bash -s' << 'SCRIPT_REMOTO'

echo "PASO 1: Configurando bind-address en MySQL..."

# Crear archivo de configuración para acceso remoto
cat > /etc/my.cnf.d/remote-access.cnf << 'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF

echo "✓ Archivo creado: /etc/my.cnf.d/remote-access.cnf"

echo ""
echo "PASO 2: Creando/actualizando usuario MySQL..."

# Leer contraseña de root MySQL
read -s -p "Ingresa la contraseña de root de MySQL: " MYSQL_ROOT_PASS
echo

# Ejecutar SQL para crear usuario
mysql -u root -p"$MYSQL_ROOT_PASS" << 'EOSQL'
-- Eliminar usuario si existe
DROP USER IF EXISTS 'controlcd_admin'@'%';
DROP USER IF EXISTS 'controlcd_admin'@'localhost';

-- Crear usuario con acceso desde CUALQUIER HOST
CREATE USER 'controlcd_admin'@'%' IDENTIFIED WITH mysql_native_password BY '55a30c6c-2473-4279-823a-261f2cce92ee';

-- Dar todos los permisos sobre la base de datos
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_admin'@'%';

-- Aplicar cambios
FLUSH PRIVILEGES;

-- Verificar
SELECT User, Host FROM mysql.user WHERE User = 'controlcd_admin';
SHOW GRANTS FOR 'controlcd_admin'@'%';
EOSQL

echo ""
echo "PASO 3: Reiniciando MySQL..."
systemctl restart mysqld

echo ""
echo "PASO 4: Verificando que MySQL escuche en 0.0.0.0:3306..."
sleep 3
netstat -tlnp | grep 3306 || ss -tlnp | grep 3306

echo ""
echo "✓ Configuración completada!"

SCRIPT_REMOTO

echo ""
echo "=============================================="
echo "✓ MySQL configurado para acceso remoto"
echo "=============================================="
echo ""
echo "Prueba la conexión desde tu PC con:"
echo ""
echo "mysql -h 128.199.1.223 -u controlcd_admin -p'55a30c6c-2473-4279-823a-261f2cce92ee' controlcd_prod"
echo ""
echo "O mejor aún, usa un cliente GUI como:"
echo "- DBeaver"
echo "- MySQL Workbench"
echo "- TablePlus"
echo ""
