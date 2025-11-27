#!/bin/bash
#
# Script para crear usuario controlcd_prod para acceso LOCAL
# (necesario para que Laravel se conecte a MySQL)
#

echo "=========================================================="
echo "Crear usuarios MySQL locales para Laravel"
echo "=========================================================="
echo ""
echo "Este script creará el usuario 'controlcd_prod' para acceso LOCAL"
echo "(usado por la aplicación Laravel en el servidor)"
echo ""

read -p "¿Continuar? (s/n): " confirm
if [ "$confirm" != "s" ]; then
    exit 0
fi

echo ""
read -s -p "Ingresa la contraseña de ROOT de MySQL: " MYSQL_ROOT_PASS
echo ""
echo ""

ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223 bash -s "$MYSQL_ROOT_PASS" << 'ENDSSH'

MYSQL_PASS="$1"

echo "Creando usuarios MySQL..."

mysql -u root -p"$MYSQL_PASS" << 'EOSQL'

-- Crear/actualizar usuario para localhost (conexión local de Laravel)
DROP USER IF EXISTS 'controlcd_prod'@'localhost';
DROP USER IF EXISTS 'controlcd_prod'@'127.0.0.1';

CREATE USER 'controlcd_prod'@'localhost'
  IDENTIFIED WITH mysql_native_password
  BY 'ControCD2025!Prod#DB';

CREATE USER 'controlcd_prod'@'127.0.0.1'
  IDENTIFIED WITH mysql_native_password
  BY 'ControCD2025!Prod#DB';

-- Dar permisos completos
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'localhost';
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Mostrar usuarios
SELECT 'Usuarios MySQL configurados:' as '';
SELECT User, Host FROM mysql.user WHERE User = 'controlcd_prod';

EOSQL

if [ $? -eq 0 ]; then
    echo ""
    echo "✓ Usuarios creados correctamente"
    echo ""
    echo "Limpiando cache de Laravel..."
    cd /var/www/controlcd-api
    php artisan cache:clear
    php artisan config:clear
    echo "✓ Cache limpiado"
else
    echo "✗ Error al crear usuarios"
    exit 1
fi

ENDSSH

if [ $? -eq 0 ]; then
    echo ""
    echo "=========================================================="
    echo "✓ CONFIGURACIÓN COMPLETADA!"
    echo "=========================================================="
    echo ""
    echo "Ahora el API debería funcionar correctamente."
    echo ""
    echo "Probando..."
    curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" https://api.control-cd.com/
else
    echo "✗ Error en la configuración"
    exit 1
fi
