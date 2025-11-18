#!/bin/bash
#
# Script de configuración del Backend Laravel
# ControCD - Backend Setup
#

set -e

echo "=================================="
echo "Configuración del Backend"
echo "Laravel - ControCD"
echo "=================================="
echo ""

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Solicitar información
read -p "Dominio de la API (ej: api.tudominio.com): " API_DOMAIN
if [ -z "$API_DOMAIN" ]; then
    echo -e "${RED}Error: El dominio no puede estar vacío${NC}"
    exit 1
fi

read -p "Ruta del proyecto en el servidor [/var/www/controlcd-api]: " PROJECT_PATH
PROJECT_PATH=${PROJECT_PATH:-/var/www/controlcd-api}

# Crear directorio del proyecto
echo -e "${YELLOW}Creando directorio del proyecto...${NC}"
sudo mkdir -p $PROJECT_PATH
sudo chown -R $USER:$USER $PROJECT_PATH

# Copiar archivos del proyecto
echo -e "${YELLOW}Copiando archivos del proyecto...${NC}"
echo "Debes subir los archivos del proyecto a: $PROJECT_PATH"
echo "Usa rsync o git clone para subir el código"
read -p "¿Ya tienes los archivos en $PROJECT_PATH? (s/n): " FILES_READY

if [ "$FILES_READY" != "s" ]; then
    echo ""
    echo "Sube los archivos y vuelve a ejecutar este script."
    echo "Ejemplo con rsync:"
    echo "  rsync -avz --exclude 'vendor' --exclude 'node_modules' ./ usuario@servidor:$PROJECT_PATH/"
    exit 0
fi

# Navegar al directorio
cd $PROJECT_PATH

# Crear archivo .env
echo -e "${YELLOW}Configurando archivo .env...${NC}"

if [ -f /tmp/db_config.txt ]; then
    source /tmp/db_config.txt
else
    read -p "Nombre de la base de datos: " DB_DATABASE
    read -p "Usuario de la base de datos: " DB_USERNAME
    read -sp "Contraseña de la base de datos: " DB_PASSWORD
    echo ""
fi

cat > .env <<EOF
APP_NAME=ControlCD
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${API_DOMAIN}
APP_TIMEZONE=UTC

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"
EOF

# Instalar dependencias de Composer
echo -e "${YELLOW}Instalando dependencias de Composer...${NC}"
composer install --optimize-autoloader --no-dev --no-interaction

# Generar key de aplicación
echo -e "${YELLOW}Generando APP_KEY...${NC}"
php artisan key:generate --force

# Generar keys de Passport
echo -e "${YELLOW}Generando Passport keys...${NC}"
php artisan passport:keys --force

# Ejecutar migraciones
echo -e "${YELLOW}Ejecutando migraciones...${NC}"
php artisan migrate --force

# Cachear configuraciones
echo -e "${YELLOW}Cacheando configuraciones...${NC}"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Configurar permisos
echo -e "${YELLOW}Configurando permisos...${NC}"
sudo chown -R nginx:nginx $PROJECT_PATH
sudo chmod -R 755 $PROJECT_PATH
sudo chmod -R 775 $PROJECT_PATH/storage
sudo chmod -R 775 $PROJECT_PATH/bootstrap/cache

# Configurar SELinux contexts
echo -e "${YELLOW}Configurando SELinux...${NC}"
sudo chcon -R -t httpd_sys_rw_content_t $PROJECT_PATH/storage
sudo chcon -R -t httpd_sys_rw_content_t $PROJECT_PATH/bootstrap/cache

echo ""
echo -e "${GREEN}✓ Backend configurado correctamente!${NC}"
echo ""
echo "Ruta del proyecto: $PROJECT_PATH"
echo "Dominio API: $API_DOMAIN"
echo ""
echo "Siguiente paso: Ejecutar 04-setup-nginx.sh"
