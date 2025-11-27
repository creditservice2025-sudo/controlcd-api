#!/bin/bash

# Configuration
SERVER_IP="128.199.1.223"
SERVER_USER="root"
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"

echo "🔧 Reparando permisos de Nginx en Producción..."

ssh -i $SSH_KEY $SERVER_USER@$SERVER_IP << EOF
    # 1. Corregir permisos del archivo de configuración
    chmod 644 /etc/nginx/conf.d/control-cd-versions.conf
    chown root:root /etc/nginx/conf.d/control-cd-versions.conf

    # 2. Corregir contexto SELinux (si aplica)
    if command -v restorecon &> /dev/null; then
        restorecon -v /etc/nginx/conf.d/control-cd-versions.conf
    fi

    # 3. Verificar configuración y recargar
    nginx -t
    if [ \$? -eq 0 ]; then
        systemctl reload nginx
        echo "✅ Nginx recargado correctamente"

        # 4. Reintentar Certbot
        echo "🔒 Reintentando configuración SSL..."
        certbot --nginx -d apk.control-cd.com --non-interactive --agree-tos -m admin@control-cd.com --redirect
    else
        echo "❌ Error en la configuración de Nginx"
        exit 1
    fi
EOF
