#!/bin/bash
#
# Script para sincronizar imágenes de Staging a Producción
# ControCD - Image Sync Script
#
# Este script copia las imágenes desde staging sin eliminar las existentes en producción
#

set -e

# Configuración
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
STAGING_SERVER="root@146.190.147.164"
PROD_SERVER="root@128.199.1.223"
REMOTE_PATH="/var/www/controlcd-api/public/images/"
TEMP_DIR="/tmp/controlcd-images-sync"

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=========================================="
echo "ControCD - Sincronización de Imágenes"
echo "Staging → Producción"
echo -e "==========================================${NC}"
echo ""

# Verificar clave SSH
if [ ! -f "$SSH_KEY" ]; then
    echo -e "${RED}Error: No se encuentra la clave SSH en $SSH_KEY${NC}"
    exit 1
fi

echo -e "${YELLOW}Configuración:${NC}"
echo "  Origen: $STAGING_SERVER:$REMOTE_PATH"
echo "  Destino: $PROD_SERVER:$REMOTE_PATH"
echo "  Modo: Incremental (no borra archivos existentes)"
echo ""

read -p "¿Deseas continuar con la sincronización? (s/n): " CONFIRM
if [ "$CONFIRM" != "s" ]; then
    echo "Sincronización cancelada."
    exit 0
fi

echo ""
echo -e "${YELLOW}Iniciando sincronización...${NC}"
echo ""

# Crear directorio temporal local
mkdir -p "$TEMP_DIR"

# PASO 1: Descargar imágenes desde STAGING a local
echo -e "${YELLOW}[1/3] Descargando imágenes desde Staging...${NC}"
rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  --exclude='.git' \
  --exclude='.gitignore' \
  "$STAGING_SERVER:$REMOTE_PATH" \
  "$TEMP_DIR/"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Descarga completada${NC}"
else
    echo -e "${RED}✗ Error al descargar desde staging${NC}"
    exit 1
fi

echo ""

# PASO 2: Subir imágenes desde local a PRODUCCIÓN (sin borrar)
echo -e "${YELLOW}[2/3] Subiendo imágenes a Producción...${NC}"
rsync -avzP \
  -e "ssh -i $SSH_KEY" \
  --ignore-existing \
  "$TEMP_DIR/" \
  "$PROD_SERVER:$REMOTE_PATH"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Subida completada${NC}"
else
    echo -e "${RED}✗ Error al subir a producción${NC}"
    exit 1
fi

echo ""

# PASO 3: Configurar permisos en producción
echo -e "${YELLOW}[3/3] Configurando permisos en producción...${NC}"
ssh -i "$SSH_KEY" $PROD_SERVER << 'ENDSSH'
cd /var/www/controlcd-api/public/images
chown -R nginx:nginx .
chmod -R 755 .
echo "✓ Permisos configurados"
ENDSSH

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Permisos actualizados${NC}"
fi

echo ""

# Limpiar archivos temporales
echo -e "${YELLOW}Limpiando archivos temporales...${NC}"
rm -rf "$TEMP_DIR"
echo -e "${GREEN}✓ Limpieza completada${NC}"

echo ""
echo -e "${GREEN}=========================================="
echo "✓ Sincronización completada exitosamente"
echo -e "==========================================${NC}"
echo ""

# Mostrar estadísticas
echo -e "${YELLOW}Verificando imágenes en producción...${NC}"
ssh -i "$SSH_KEY" $PROD_SERVER << 'ENDSSH'
echo "Estadísticas del directorio de imágenes:"
cd /var/www/controlcd-api/public/images
echo "  Total de archivos: $(find . -type f | wc -l)"
echo "  Total de directorios: $(find . -type d | wc -l)"
echo "  Espacio usado: $(du -sh . | cut -f1)"
ENDSSH

echo ""
echo -e "${BLUE}Próxima ejecución recomendada:${NC}"
echo "  Para mantener sincronizado, ejecuta este script periódicamente"
echo "  o agrégalo a un cron job diario."
