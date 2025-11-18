#!/bin/bash
#
# Script de instalación de dependencias para AlmaLinux
# ControCD - Backend Setup
#

set -e

echo "=================================="
echo "Instalación de Dependencias"
echo "AlmaLinux - ControCD"
echo "=================================="
echo ""

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Actualizar sistema
echo -e "${YELLOW}[1/7] Actualizando sistema...${NC}"
sudo dnf update -y

# Instalar EPEL y Remi repositories
echo -e "${YELLOW}[2/7] Instalando repositorios EPEL y Remi...${NC}"
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset php -y
sudo dnf module enable php:remi-8.1 -y

# Instalar PHP 8.1 y extensiones
echo -e "${YELLOW}[3/7] Instalando PHP 8.1 y extensiones...${NC}"
sudo dnf install -y php php-cli php-fpm php-mysqlnd php-zip php-devel \
    php-gd php-mcrypt php-mbstring php-curl php-xml php-pear php-bcmath \
    php-json php-opcache php-intl php-soap

# Instalar MySQL 8.0
echo -e "${YELLOW}[4/7] Instalando MySQL 8.0...${NC}"
sudo dnf install -y mysql-server mysql

# Iniciar y habilitar MySQL
sudo systemctl start mysqld
sudo systemctl enable mysqld

# Instalar Nginx
echo -e "${YELLOW}[5/7] Instalando Nginx...${NC}"
sudo dnf install -y nginx

# Iniciar y habilitar Nginx
sudo systemctl start nginx
sudo systemctl enable nginx

# Instalar Composer
echo -e "${YELLOW}[6/7] Instalando Composer...${NC}"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Instalar Node.js 20
echo -e "${YELLOW}[7/7] Instalando Node.js 20...${NC}"
curl -fsSL https://rpm.nodesource.com/setup_20.x | sudo bash -
sudo dnf install -y nodejs

# Configurar firewall
echo -e "${YELLOW}Configurando firewall...${NC}"
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload

# Configurar SELinux para Nginx
echo -e "${YELLOW}Configurando SELinux...${NC}"
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_unified 1
sudo setsebool -P httpd_execmem 1

# Verificar instalaciones
echo ""
echo -e "${GREEN}=================================="
echo "Verificación de Instalaciones"
echo -e "==================================${NC}"
echo "PHP: $(php -v | head -n 1)"
echo "Composer: $(composer --version)"
echo "Node.js: $(node -v)"
echo "npm: $(npm -v)"
echo "Nginx: $(nginx -v 2>&1)"
echo "MySQL: $(mysql --version)"

echo ""
echo -e "${GREEN}✓ Todas las dependencias instaladas correctamente!${NC}"
echo ""
echo "Siguiente paso: Ejecutar 02-setup-database.sh"
