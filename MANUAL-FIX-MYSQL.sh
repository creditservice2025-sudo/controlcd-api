#!/bin/bash
#
# Script de diagnóstico y reparación final de MySQL para Laravel
#

echo "============================================================"
echo "Diagnóstico y Reparación de MySQL - PRODUCCIÓN"
echo "============================================================"
echo ""
echo "Este script te ayudará a diagnosticar y reparar el problema"
echo "de conexión de Laravel a MySQL."
echo ""

echo "PASOS A SEGUIR:"
echo ""
echo "1. Conecta al servidor manualmente:"
echo "   ssh -i ~/.ssh/id_rsa_mario_controlcd root@128.199.1.223"
echo ""
echo "2. Conéctate a MySQL como root:"
echo "   mysql -u root -p"
echo "   (Ingresa la contraseña de root de MySQL)"
echo ""
echo "3. Ejecuta estos comandos SQL:"
echo ""
cat << 'SQL'
-- Ver usuarios actuales
SELECT User, Host, plugin FROM mysql.user WHERE User = 'controlcd_prod';

-- Eliminar usuarios existentes
DROP USER IF EXISTS 'controlcd_prod'@'localhost';
DROP USER IF EXISTS 'controlcd_prod'@'127.0.0.1';

-- Crear usuarios con contraseña correcta
CREATE USER 'controlcd_prod'@'localhost'
  IDENTIFIED BY 'ControCD2025!Prod#DB';

CREATE USER 'controlcd_prod'@'127.0.0.1'
  IDENTIFIED BY 'ControCD2025!Prod#DB';

-- Dar permisos completos
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'localhost';
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Verificar
SELECT User, Host, plugin FROM mysql.user WHERE User = 'controlcd_prod';
SHOW GRANTS FOR 'controlcd_prod'@'localhost';

-- Salir de MySQL
EXIT;
SQL

echo ""
echo "4. Prueba la conexión desde línea de comandos:"
echo "   mysql -h localhost -u controlcd_prod -p'ControCD2025!Prod#DB' controlcd_prod -e \"SELECT 'OK' as status;\""
echo ""
echo "5. Limpia cache de Laravel y reinicia PHP-FPM:"
echo ""
cat << 'BASH'
cd /var/www/controlcd-api
php artisan config:clear
php artisan cache:clear
systemctl restart php-fpm
BASH

echo ""
echo "6. Prueba Laravel:"
echo "   php artisan tinker --execute=\"DB::connection()->getPdo(); echo 'Connected!';\" "
echo ""
echo "7. Prueba el API:"
echo "   curl https://api.control-cd.com/"
echo ""
echo "============================================================"
