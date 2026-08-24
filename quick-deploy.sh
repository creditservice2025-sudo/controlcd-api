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
#
# OJO con --delete: borra en el servidor todo lo que no esté en la copia
# local. Las fotos que suben los cobradores viven en public/images y NO están
# en el repo, así que sin este exclude un deploy las elimina de producción
# (fotos de créditos, documentos y perfiles que ya no se pueden recuperar).
# Mismo criterio que deploy-to-server-prod.sh y el .rsyncignore del CI.
# El .env del servidor tiene credenciales propias: tampoco se pisa.
rsync -avzP --delete \
  -e "ssh -i $SSH_KEY" \
  --exclude='.git' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='.env' \
  --exclude='.env.staging' \
  --exclude='.env.prod' \
  --exclude='public/images' \
  --exclude='storage/app' \
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
