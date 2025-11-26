#!/bin/bash

# Script para configurar crons en el servidor de producción
# Servidor: 146.190.147.164 (cPanel con ea-php83)
# Usuario: staging

set -e

echo "=========================================="
echo "   CONFIGURACIÓN DE CRONS - ControCD API"
echo "=========================================="
echo ""

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Variables
PROJECT_PATH="/var/www/controlcd-api"
PHP_PATH="/opt/cpanel/ea-php83/root/usr/bin/php"
CRON_USER="staging"

echo -e "${YELLOW}📋 Comandos Cron configurados:${NC}"
echo "  1. liquidation:notify-pending  → 21:52 (9:52 PM)"
echo "  2. liquidation:auto-daily      → 23:55 (11:55 PM)"
echo "  3. liquidation:historical      → 23:55 (11:55 PM)"
echo ""

# Función para verificar si estamos en el servidor
check_server() {
    if [[ "$HOSTNAME" == *"control-cd"* ]] || [[ -d "$PROJECT_PATH" ]]; then
        return 0
    else
        return 1
    fi
}

# Función para instalar cron
install_cron() {
    echo -e "${GREEN}Instalando cron en el servidor...${NC}"
    
    # Línea del cron
    CRON_LINE="* * * * * cd $PROJECT_PATH && $PHP_PATH artisan schedule:run >> /dev/null 2>&1"
    
    # Verificar si ya existe
    if crontab -u $CRON_USER -l 2>/dev/null | grep -q "schedule:run"; then
        echo -e "${YELLOW}⚠️  El cron ya está configurado${NC}"
        echo ""
        echo "Cron actual:"
        crontab -u $CRON_USER -l | grep "schedule:run"
        echo ""
        read -p "¿Deseas reemplazarlo? (y/n): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Cancelado."
            return
        fi
        
        # Remover línea existente
        crontab -u $CRON_USER -l | grep -v "schedule:run" | crontab -u $CRON_USER -
    fi
    
    # Agregar nuevo cron
    (crontab -u $CRON_USER -l 2>/dev/null; echo "$CRON_LINE") | crontab -u $CRON_USER -
    
    echo -e "${GREEN}✅ Cron instalado correctamente${NC}"
    echo ""
    echo "Crontab configurado:"
    crontab -u $CRON_USER -l
}

# Función para verificar comandos
verify_commands() {
    echo -e "${GREEN}Verificando comandos artisan...${NC}"
    cd $PROJECT_PATH
    
    echo ""
    echo "Comandos disponibles:"
    $PHP_PATH artisan list | grep "liquidation:"
    echo ""
}

# Función para probar comandos
test_commands() {
    echo -e "${YELLOW}Probando comandos manualmente...${NC}"
    cd $PROJECT_PATH
    
    echo ""
    echo "1. Probando liquidation:notify-pending..."
    $PHP_PATH artisan liquidation:notify-pending
    echo ""
    
    read -p "¿Continuar con liquidation:auto-daily? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "2. Probando liquidation:auto-daily..."
        $PHP_PATH artisan liquidation:auto-daily
        echo ""
    fi
    
    read -p "¿Continuar con liquidation:historical? (CUIDADO: puede tardar mucho) (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "3. Probando liquidation:historical..."
        $PHP_PATH artisan liquidation:historical
        echo ""
    fi
    
    echo -e "${GREEN}✅ Pruebas completadas${NC}"
}

# Función para ver schedule
show_schedule() {
    echo -e "${GREEN}Schedule programado:${NC}"
    cd $PROJECT_PATH
    $PHP_PATH artisan schedule:list
}

# Función para ver logs
show_logs() {
    echo -e "${GREEN}Últimas líneas del log de Laravel:${NC}"
    tail -50 $PROJECT_PATH/storage/logs/laravel.log
}

# Función para verificar permisos
check_permissions() {
    echo -e "${GREEN}Verificando permisos...${NC}"
    
    echo "Permisos de storage:"
    ls -la $PROJECT_PATH/storage | head -10
    
    echo ""
    echo "Permisos de bootstrap/cache:"
    ls -la $PROJECT_PATH/bootstrap/cache | head -10
    
    echo ""
    read -p "¿Corregir permisos? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        chown -R $CRON_USER:$CRON_USER $PROJECT_PATH/storage $PROJECT_PATH/bootstrap/cache
        chmod -R 775 $PROJECT_PATH/storage $PROJECT_PATH/bootstrap/cache
        chcon -R -t httpd_sys_rw_content_t $PROJECT_PATH/storage $PROJECT_PATH/bootstrap/cache 2>/dev/null || true
        echo -e "${GREEN}✅ Permisos corregidos${NC}"
    fi
}

# Menú principal
show_menu() {
    echo ""
    echo "=========================================="
    echo "           MENÚ PRINCIPAL"
    echo "=========================================="
    echo "1. Instalar/Actualizar Cron"
    echo "2. Verificar Comandos Artisan"
    echo "3. Ver Schedule Programado"
    echo "4. Probar Comandos Manualmente"
    echo "5. Ver Logs de Laravel"
    echo "6. Verificar/Corregir Permisos"
    echo "7. Ver Crontab Actual"
    echo "8. Salir"
    echo ""
    read -p "Selecciona una opción (1-8): " option
    
    case $option in
        1)
            install_cron
            ;;
        2)
            verify_commands
            ;;
        3)
            show_schedule
            ;;
        4)
            test_commands
            ;;
        5)
            show_logs
            ;;
        6)
            check_permissions
            ;;
        7)
            echo "Crontab actual del usuario $CRON_USER:"
            crontab -u $CRON_USER -l
            ;;
        8)
            echo "¡Hasta luego!"
            exit 0
            ;;
        *)
            echo -e "${RED}Opción inválida${NC}"
            ;;
    esac
    
    # Volver a mostrar el menú
    show_menu
}

# Verificar si estamos en el servidor
if ! check_server; then
    echo -e "${RED}❌ Este script debe ejecutarse en el servidor de producción${NC}"
    echo ""
    echo "Para ejecutar en el servidor:"
    echo "  ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164"
    echo "  cd /var/www/controlcd-api"
    echo "  ./setup-cron.sh"
    exit 1
fi

# Verificar si somos root
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}❌ Este script debe ejecutarse como root${NC}"
    echo "Ejecuta: sudo ./setup-cron.sh"
    exit 1
fi

# Mostrar menú
show_menu
