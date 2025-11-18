#!/bin/bash
#
# Script para solucionar problemas de CORS
# ControCD - CORS Fix
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=================================="
echo "Solucionando Problemas de CORS"
echo "ControCD"
echo -e "==================================${NC}"
echo ""

API_DOMAIN="staging-api.control-cd.com"
APP_DOMAIN="staging.control-cd.com"
BACKEND_PATH="/var/www/controlcd-api"

echo -e "${YELLOW}[1/5] Actualizando configuración de Nginx...${NC}"

# Crear nueva configuración con headers CORS
sudo tee /etc/nginx/conf.d/controlcd-staging.conf > /dev/null <<'EOF'
# Backend API
server {
    listen 80;
    server_name staging-api.control-cd.com;
    root /var/www/controlcd-api/public;
    
    index index.php index.html;
    
    client_max_body_size 100M;
    
    access_log /var/log/nginx/controlcd-api-access.log;
    error_log /var/log/nginx/controlcd-api-error.log;
    
    # Headers CORS globales
    add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
    add_header 'Access-Control-Allow-Credentials' 'true' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token' always;
    
    location / {
        # Manejar preflight OPTIONS
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
            add_header 'Access-Control-Allow-Credentials' 'true' always;
            add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
            add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token' always;
            add_header 'Access-Control-Max-Age' 86400 always;
            add_header 'Content-Type' 'text/plain charset=UTF-8' always;
            add_header 'Content-Length' 0 always;
            return 204;
        }
        
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        # Manejar preflight OPTIONS para PHP
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
            add_header 'Access-Control-Allow-Credentials' 'true' always;
            add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
            add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token' always;
            add_header 'Access-Control-Max-Age' 86400 always;
            add_header 'Content-Type' 'text/plain charset=UTF-8' always;
            add_header 'Content-Length' 0 always;
            return 204;
        }
        
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "upload_max_filesize=100M \n post_max_size=100M";
    }
    
    location ~ /\.(?!well-known).* {
        deny all;
    }
    
    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}

# Frontend App
server {
    listen 80;
    server_name staging.control-cd.com;
    root /var/www/controlcd-app;
    
    index index.html;
    
    access_log /var/log/nginx/controlcd-app-access.log;
    error_log /var/log/nginx/controlcd-app-error.log;
    
    location / {
        try_files $uri $uri/ /index.html;
    }
    
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires max;
        log_not_found off;
        add_header Cache-Control "public, immutable";
    }
    
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss application/json application/javascript;
}
EOF

echo -e "${GREEN}✓ Configuración de Nginx actualizada${NC}"

echo ""
echo -e "${YELLOW}[2/5] Verificando configuración de Nginx...${NC}"
sudo nginx -t

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Error en la configuración de Nginx${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}[3/5] Reiniciando Nginx...${NC}"
sudo systemctl reload nginx

echo ""
echo -e "${YELLOW}[4/5] Limpiando cache de Laravel...${NC}"
cd $BACKEND_PATH
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo ""
echo -e "${YELLOW}[5/5] Cacheando configuraciones...${NC}"
php artisan config:cache

echo ""
echo -e "${GREEN}=================================="
echo "✓ CORS Configurado Exitosamente!"
echo -e "==================================${NC}"
echo ""
echo -e "${BLUE}Pruebas:${NC}"
echo ""
echo "1. Limpia la cache del navegador (Ctrl + Shift + R)"
echo ""
echo "2. Prueba con curl:"
echo "   curl -I -X OPTIONS \\"
echo "     -H \"Origin: https://${APP_DOMAIN}\" \\"
echo "     -H \"Access-Control-Request-Method: POST\" \\"
echo "     https://${API_DOMAIN}/api/login"
echo ""
echo "3. Intenta hacer login desde: https://${APP_DOMAIN}"
echo ""
echo -e "${YELLOW}Si el problema persiste:${NC}"
echo "- Ver logs: sudo tail -f /var/log/nginx/controlcd-api-error.log"
echo "- Ver logs Laravel: tail -f ${BACKEND_PATH}/storage/logs/laravel.log"
