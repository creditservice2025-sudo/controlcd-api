#!/bin/bash
#
# Script simplificado para crear usuario MySQL remoto
#

echo "=========================================================="
echo "Crear Usuario MySQL Remoto - PRODUCCIÓN"
echo "=========================================================="
echo ""
echo "Usuario: controlcd_admin"
echo "Contraseña: 55a30c6c-2473-4279-823a-261f2cce92ee"
echo "Tu IP: 179.63.37.30"
echo ""

read -p "¿Continuar? (s/n): " confirm
if [ "$confirm" != "s" ]; then
    exit 0
fi

# Pedir contraseña de MySQL root ANTES de conectar
echo ""
read -s -p "Ingresa la contraseña de ROOT de MySQL: " MYSQL_ROOT_PASS
echo ""
echo ""

if [ -z "$MYSQL_ROOT_PASS" ]; then
    echo "Error: Debes ingresar la contraseña"
    exit 1
fi

echo "Conectando al servidor..."

# Ejecutar en el servidor pasando la contraseña como variable
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223 bash -s "$MYSQL_ROOT_PASS" << 'ENDSSH'

MYSQL_PASS="$1"

echo "Ejecutando comandos MySQL..."

mysql -u root -p"$MYSQL_PASS" << 'EOSQL'

-- Ver políticas de contraseña actuales
SELECT 'Política de contraseñas actual:' as '';
SHOW VARIABLES LIKE 'validate_password%';

-- Guardar configuración actual
SET @old_validate_password_policy = @@global.validate_password.policy;
SET @old_validate_password_length = @@global.validate_password.length;

-- Deshabilitar temporalmente la validación de contraseñas
SET GLOBAL validate_password.policy = LOW;
SET GLOBAL validate_password.length = 4;

-- Ver usuarios actuales
SELECT 'Usuarios actuales:' as '';
SELECT User, Host FROM mysql.user WHERE User LIKE '%controlcd%';

-- Eliminar usuarios antiguos
DROP USER IF EXISTS 'controlcd_admin'@'%';
DROP USER IF EXISTS 'controlcd_admin'@'179.63.37.30';

-- Crear usuario para CUALQUIER IP
CREATE USER 'controlcd_admin'@'%'
  IDENTIFIED WITH mysql_native_password
  BY '55a30c6c-2473-4279-823a-261f2cce92ee';

-- Crear usuario para IP específica
CREATE USER 'controlcd_admin'@'179.63.37.30'
  IDENTIFIED WITH mysql_native_password
  BY '55a30c6c-2473-4279-823a-261f2cce92ee';

-- Dar permisos completos
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_admin'@'%';
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_admin'@'179.63.37.30';

FLUSH PRIVILEGES;

-- Restaurar política de contraseñas
SET GLOBAL validate_password.policy = @old_validate_password_policy;
SET GLOBAL validate_password.length = @old_validate_password_length;

-- Verificar usuarios creados
SELECT 'Usuarios creados:' as '';
SELECT User, Host, plugin FROM mysql.user WHERE User = 'controlcd_admin';

EOSQL

MYSQL_RESULT=$?

if [ $MYSQL_RESULT -eq 0 ]; then
    echo ""
    echo "✓ Usuarios creados correctamente"

    # Configurar bind-address
    echo ""
    echo "Configurando bind-address..."

    cat > /etc/my.cnf.d/remote.cnf << 'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF

    echo "✓ Archivo /etc/my.cnf.d/remote.cnf creado"

    echo ""
    echo "Reiniciando MySQL..."
    systemctl restart mysqld
    sleep 3

    echo "✓ MySQL reiniciado"

    echo ""
    echo "Puerto 3306:"
    ss -tlnp | grep 3306 || netstat -tlnp | grep 3306

else
    echo "✗ Error al ejecutar comandos MySQL"
    exit 1
fi

ENDSSH

SSH_RESULT=$?

echo ""
if [ $SSH_RESULT -eq 0 ]; then
    echo "=========================================================="
    echo "✓ CONFIGURACIÓN COMPLETADA!"
    echo "=========================================================="
    echo ""
    echo "Ahora puedes conectarte con:"
    echo ""
    echo "  mysql -h 128.199.1.223 -u controlcd_admin -p controlcd_prod"
    echo ""
    echo "  Contraseña: 55a30c6c-2473-4279-823a-261f2cce92ee"
    echo ""
    echo "O usando DBeaver/MySQL Workbench/TablePlus"
    echo ""
else
    echo "✗ Error en la configuración"
    exit 1
fi
