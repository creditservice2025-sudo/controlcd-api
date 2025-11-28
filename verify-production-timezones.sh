#!/bin/bash

# Colores
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

echo "========================================"
echo "Verificando Zonas Horarias (America/Lima)"
echo "========================================"

# 1. Verificar Sistema
echo -n "Sistema: "
SYSTEM_TZ=$(timedatectl | grep "Time zone" | awk '{print $3}')
if [[ "$SYSTEM_TZ" == "America/Lima" ]]; then
    echo -e "${GREEN}OK ($SYSTEM_TZ)${NC}"
else
    echo -e "${RED}ERROR ($SYSTEM_TZ)${NC}"
fi

# 2. Verificar PHP CLI
echo -n "PHP CLI: "
PHP_TZ=$(php -r "echo date_default_timezone_get();")
if [[ "$PHP_TZ" == "America/Lima" ]]; then
    echo -e "${GREEN}OK ($PHP_TZ)${NC}"
else
    echo -e "${RED}ERROR ($PHP_TZ)${NC}"
fi

# 3. Verificar MySQL
echo -n "MySQL:   "
# Pedir password si no se pasa como argumento
MYSQL_TZ=$(mysql -u root -p -N -e "SELECT @@global.time_zone;")
if [[ "$MYSQL_TZ" == "America/Lima" ]]; then
    echo -e "${GREEN}OK ($MYSQL_TZ)${NC}"
else
    echo -e "${RED}ERROR ($MYSQL_TZ)${NC}"
fi

echo "========================================"
echo "Hora actual en cada servicio:"
echo "Sistema: $(date)"
echo "PHP:     $(php -r "echo date('Y-m-d H:i:s');")"
echo "MySQL:   $(mysql -u root -p -N -e "SELECT NOW();")"
