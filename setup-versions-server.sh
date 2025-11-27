#!/bin/bash

# Configuration
SERVER_IP="146.190.147.164"
SERVER_USER="root"
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
NGINX_CONF="server-conf/nginx-versions.conf"

echo "🚀 Configurando servidor de versiones..."

# 1. Crear directorios
echo "📂 Creando directorios en el servidor..."
ssh -i $SSH_KEY $SERVER_USER@$SERVER_IP "mkdir -p /var/www/control-cd-versions/staging /var/www/control-cd-versions/production"

# 2. Subir configuración de Nginx
echo "uploading Nginx config..."
scp -i $SSH_KEY $NGINX_CONF $SERVER_USER@$SERVER_IP:/tmp/nginx-versions.conf

# 3. Habilitar sitio y recargar Nginx
echo "🔧 Habilitando sitio..."
ssh -i $SSH_KEY $SERVER_USER@$SERVER_IP "mv /tmp/nginx-versions.conf /etc/nginx/conf.d/control-cd-versions.conf && nginx -t && systemctl reload nginx"

# 4. Configurar SSL con Certbot
echo "🔒 Configurando SSL..."
# Usamos --nginx para que certbot configure automáticamente el SSL en el archivo que acabamos de subir
ssh -i $SSH_KEY $SERVER_USER@$SERVER_IP "certbot --nginx -d staging-apk.control-cd.com --non-interactive --agree-tos -m admin@control-cd.com --redirect"

echo "✅ Servidor configurado exitosamente!"
