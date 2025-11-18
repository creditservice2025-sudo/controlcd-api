#!/bin/bash
#
# Script de configuración de Nginx
# ControCD - Nginx Setup
#

set -e

echo "=================================="
echo "Configuración de Nginx"
echo "ControCD"
echo "=================================="
echo ""

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Solicitar información
read -p "Dominio principal (ej: tudominio.com): " MAIN_DOMAIN
read -p "Dominio de la API (ej: api.tudominio.com): " API_DOMAIN

if [ -z "$MAIN_DOMAIN" ] || [ -z "$API_DOMAIN" ]; then
    echo -e "${RED}Error: Los dominios no pueden estar vacíos${NC}"
    exit 1
fi

read -p "Ruta del backend [/var/www/controlcd-api]: " BACKEND_PATH
BACKEND_PATH=${BACKEND_PATH:-/var/www/controlcd-api}

read -p "Ruta del frontend [/var/www/controlcd-app]: " FRONTEND_PATH
FRONTEND_PATH=${FRONTEND_PATH:-/var/www/controlcd-app}

# Crear directorio del frontend
sudo mkdir -p $FRONTEND_PATH
sudo chown -R nginx:nginx $FRONTEND_PATH

# Crear configuración de Nginx para la API
echo -e "${YELLOW}Creando configuración de Nginx para la API...${NC}"

sudo tee /etc/nginx/conf.d/controlcd-api.conf > /dev/null <<EOF
server {
    listen 80;
    server_name ${API_DOMAIN};
    root ${BACKEND_PATH}/public;
    
    index index.php index.html;
    
    client_max_body_size 100M;
    
    access_log /var/log/nginx/controlcd-api-access.log;
    error_log /var/log/nginx/controlcd-api-error.log;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
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
EOF

# Crear configuración de Nginx para el Frontend
echo -e "${YELLOW}Creando configuración de Nginx para el Frontend...${NC}"

sudo tee /etc/nginx/conf.d/controlcd-app.conf > /dev/null <<EOF
server {
    listen 80;
    server_name ${MAIN_DOMAIN} www.${MAIN_DOMAIN};
    root ${FRONTEND_PATH};
    
    index index.html;
    
    access_log /var/log/nginx/controlcd-app-access.log;
    error_log /var/log/nginx/controlcd-app-error.log;
    
    location / {
        try_files \$uri \$uri/ /index.html;
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

# Configurar PHP-FPM
echo -e "${YELLOW}Configurando PHP-FPM...${NC}"
sudo sed -i 's/user = apache/user = nginx/' /etc/php-fpm.d/www.conf
sudo sed -i 's/group = apache/group = nginx/' /etc/php-fpm.d/www.conf

# Iniciar y habilitar PHP-FPM
sudo systemctl start php-fpm
sudo systemctl enable php-fpm

# Verificar configuración de Nginx
echo -e "${YELLOW}Verificando configuración de Nginx...${NC}"
sudo nginx -t

if [ $? -eq 0 ]; then
    # Reiniciar Nginx
    echo -e "${YELLOW}Reiniciando Nginx...${NC}"
    sudo systemctl restart nginx
    
    echo ""
    echo -e "${GREEN}✓ Nginx configurado correctamente!${NC}"
    echo ""
    echo "Sitios configurados:"
    echo "  - API: http://${API_DOMAIN}"
    echo "  - App: http://${MAIN_DOMAIN}"
    echo ""
    echo -e "${YELLOW}IMPORTANTE:${NC}"
    echo "1. Asegúrate de que los dominios apunten a la IP del servidor"
    echo "2. Para habilitar HTTPS, ejecuta:"
    echo "   sudo dnf install certbot python3-certbot-nginx -y"
    echo "   sudo certbot --nginx -d ${MAIN_DOMAIN} -d www.${MAIN_DOMAIN} -d ${API_DOMAIN}"
    echo ""
    echo "Siguiente paso: Ejecutar 05-deploy-frontend.sh"
else
    echo -e "${RED}✗ Error en la configuración de Nginx${NC}"
    exit 1
fi
