#!/bin/bash
#
# Script de despliegue del Frontend
# ControCD - Frontend Deployment
#

set -e

echo "=================================="
echo "Despliegue del Frontend"
echo "Quasar - ControCD"
echo "=================================="
echo ""

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

read -p "Ruta del frontend en el servidor [/var/www/controlcd-app]: " FRONTEND_PATH
FRONTEND_PATH=${FRONTEND_PATH:-/var/www/controlcd-app}

echo ""
echo -e "${YELLOW}Para desplegar el frontend, tienes 2 opciones:${NC}"
echo ""
echo "Opción 1: Compilar localmente y subir"
echo "  - En tu máquina local, ejecuta: npm run build"
echo "  - Sube los archivos: rsync -avz dist/spa/ usuario@servidor:${FRONTEND_PATH}/"
echo ""
echo "Opción 2: Usar el script deploy.sh que ya tienes"
echo "  - Modifica las credenciales FTP en deploy.sh"
echo "  - Ejecuta: ./deploy.sh"
echo ""
echo "Opción 3: Usar GitLab CI/CD"
echo "  - Configura las variables en GitLab"
echo "  - Haz push a la rama correspondiente"
echo ""

read -p "¿Quieres configurar los permisos para el directorio del frontend? (s/n): " CONFIG_PERMS

if [ "$CONFIG_PERMS" = "s" ]; then
    echo -e "${YELLOW}Configurando permisos...${NC}"
    sudo mkdir -p $FRONTEND_PATH
    sudo chown -R nginx:nginx $FRONTEND_PATH
    sudo chmod -R 755 $FRONTEND_PATH
    
    # Configurar SELinux
    sudo chcon -R -t httpd_sys_content_t $FRONTEND_PATH
    
    echo -e "${GREEN}✓ Permisos configurados!${NC}"
fi

echo ""
echo -e "${GREEN}Configuración del servidor completada!${NC}"
echo ""
echo "Resumen de rutas:"
echo "  - Backend: Verifica con 03-setup-backend.sh"
echo "  - Frontend: ${FRONTEND_PATH}"
echo ""
echo "Próximos pasos:"
echo "1. Sube los archivos del frontend a ${FRONTEND_PATH}"
echo "2. Configura tus dominios para apuntar a este servidor"
echo "3. Instala certificados SSL con certbot"
echo "4. Verifica que todo funcione correctamente"
