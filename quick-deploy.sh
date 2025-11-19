#!/bin/bash
#
# Quick Deploy - Solo sincroniza archivos y refresca config
#

set -e

SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
SERVER_USER="root"
SERVER_IP="146.190.147.164"
SERVER_PATH="/var/www/controlcd-api"
LOCAL_PATH="/home/mario-d-az/git/ControCD-Backend"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${YELLOW}Quick Deploy - Solo archivos y config${NC}"

# Rsync
rsync -avzP --delete \
  -e "ssh -i $SSH_KEY" \
  --exclude='.git' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='context/' \
  $LOCAL_PATH/ \
  $SERVER_USER@$SERVER_IP:$SERVER_PATH/

echo -e "${GREEN}✓ Archivos sincronizados${NC}"

# Solo limpiar y cachear configuraciones
echo -e "${YELLOW}Refrescando configuración...${NC}"

ssh -i "$SSH_KEY" $SERVER_USER@$SERVER_IP << 'ENDSSH'
cd /var/www/controlcd-api
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
echo "✓ Config actualizada!"
ENDSSH

echo -e "${GREEN}✓ Deploy completado!${NC}"
