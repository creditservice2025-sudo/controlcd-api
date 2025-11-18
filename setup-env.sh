#!/bin/bash
#
# Script helper para configurar .env según el ambiente
# ControCD Backend - Environment Setup
#

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=================================="
echo "Configuración de Ambiente"
echo "ControCD Backend"
echo -e "==================================${NC}"
echo ""

echo "¿Qué ambiente deseas configurar?"
echo "1) Local (desarrollo con Docker)"
echo "2) Staging (servidor staging-api.control-cd.com)"
echo "3) Production (servidor productivo)"
echo ""
read -p "Selecciona una opción (1-3): " ENV_OPTION

case $ENV_OPTION in
    1)
        echo ""
        echo -e "${YELLOW}Configurando ambiente LOCAL...${NC}"
        
        if [ -f .env ]; then
            echo -e "${YELLOW}Ya existe un archivo .env${NC}"
            read -p "¿Deseas respaldarlo y crear uno nuevo? (s/n): " BACKUP
            if [ "$BACKUP" = "s" ]; then
                mv .env .env.backup.$(date +%Y%m%d_%H%M%S)
                echo "Respaldo creado"
            else
                echo "Usando .env existente"
                exit 0
            fi
        fi
        
        cp .env.example .env
        echo -e "${GREEN}✓ Archivo .env creado para desarrollo local${NC}"
        echo ""
        echo "Recuerda ejecutar:"
        echo "  php artisan key:generate"
        echo "  php artisan passport:keys"
        ;;
        
    2)
        echo ""
        echo -e "${YELLOW}Configurando ambiente STAGING...${NC}"
        
        if [ ! -f .env.staging.example ]; then
            echo -e "${RED}Error: No se encuentra .env.staging.example${NC}"
            exit 1
        fi
        
        if [ -f .env ]; then
            echo -e "${YELLOW}Ya existe un archivo .env${NC}"
            read -p "¿Deseas respaldarlo y crear uno para staging? (s/n): " BACKUP
            if [ "$BACKUP" != "s" ]; then
                echo "Cancelado"
                exit 0
            fi
            mv .env .env.backup.$(date +%Y%m%d_%H%M%S)
            echo "Respaldo creado"
        fi
        
        cp .env.staging.example .env
        
        echo ""
        echo -e "${YELLOW}Configuración de base de datos:${NC}"
        read -p "Nombre de la base de datos [controlcd_db]: " DB_NAME
        DB_NAME=${DB_NAME:-controlcd_db}
        
        read -p "Usuario de base de datos [controlcd_user]: " DB_USER
        DB_USER=${DB_USER:-controlcd_user}
        
        read -sp "Contraseña de base de datos: " DB_PASS
        echo ""
        
        # Actualizar .env con los valores
        sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
        sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
        sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
        
        echo ""
        echo -e "${GREEN}✓ Archivo .env configurado para STAGING${NC}"
        echo ""
        echo -e "${BLUE}Configuración:${NC}"
        echo "  APP_URL: https://staging-api.control-cd.com"
        echo "  APP_ENV: staging"
        echo "  DB_DATABASE: ${DB_NAME}"
        echo "  DB_USERNAME: ${DB_USER}"
        echo ""
        echo -e "${YELLOW}IMPORTANTE:${NC}"
        echo "1. NO agregues este .env a git"
        echo "2. Despliega con: ./deploy-to-server.sh"
        echo "3. En el servidor, ejecuta:"
        echo "   php artisan key:generate"
        echo "   php artisan passport:keys"
        ;;
        
    3)
        echo ""
        echo -e "${YELLOW}Para ambiente de producción:${NC}"
        echo "Crea un .env.production con los valores correctos"
        echo "y cópialo como .env antes de desplegar"
        ;;
        
    *)
        echo "Opción inválida"
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}✓ Configuración completada${NC}"
