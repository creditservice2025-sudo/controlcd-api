# 🚀 Guía Paso a Paso - Despliegue a Producción

**Documento:** Guía definitiva para desplegar ControCD en producción  
**Fecha:** Noviembre 2025  
**Basado en:** Despliegue exitoso en Staging

---

## 📋 Prerrequisitos

### **Servidor**
- AlmaLinux (o similar RedHat/CentOS)
- Acceso root via SSH
- IP pública
- Mínimo 2GB RAM, 2 CPU cores

### **Dominios Configurados en DNS**
- `api.control-cd.com` → IP del servidor producción
- `app.control-cd.com` → IP del servidor producción (o el dominio principal)

### **Local**
- Clave SSH configurada para el servidor
- Proyectos Backend y Frontend actualizados

---

## 🎯 PASO 1: Preparar Configuración Local

### 1.1 Configurar SSH

```bash
# Generar clave SSH si no existe
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa_prod_controlcd

# Copiar clave al servidor
ssh-copy-id -i ~/.ssh/id_rsa_prod_controlcd root@TU_IP_PRODUCCION

# Probar conexión
ssh -i ~/.ssh/id_rsa_prod_controlcd root@TU_IP_PRODUCCION
```

### 1.2 Configurar SSH Config

```bash
nano ~/.ssh/config
```

Agregar:

```
Host controlcd-prod
    HostName TU_IP_PRODUCCION
    User root
    IdentityFile ~/.ssh/id_rsa_prod_controlcd
    IdentitiesOnly yes
```

### 1.3 Configurar `.env` del Backend

```bash
cd /home/mario-d-az/git/ControCD-Backend
cp .env .env.production.backup

# Editar .env
nano .env
```

**Configuración para Producción:**

```env
APP_NAME=ControlCD
APP_ENV=production
APP_KEY=base64:TU_KEY_AQUI
APP_DEBUG=false
APP_URL=https://api.control-cd.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=controlcd_prod
DB_USERNAME=controlcd_prod
DB_PASSWORD=TU_PASSWORD_SEGURO_AQUI

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

### 1.4 Configurar Frontend

```bash
cd /home/mario-d-az/git/ControCD-FrontEnd
nano .quasar.env.json
```

Asegurar que exista configuración de producción:

```json
{
  "production": {
    "ENV_TYPE": "prod",
    "API_URL": "https://api.control-cd.com",
    "UPDATE_WEB_URL": "https://app.control-cd.com",
    "GOOGLE_MAPS_API_KEY": "TU_KEY"
  }
}
```

---

## 🎯 PASO 2: Configurar Servidor

### 2.1 Conectar al Servidor

```bash
ssh controlcd-prod
```

### 2.2 Instalar Dependencias Base

```bash
# Actualizar sistema
dnf update -y

# Instalar EPEL
dnf install -y epel-release

# Instalar utilidades
dnf install -y wget curl git unzip nano rsync
```

### 2.3 Instalar PHP 8.3

```bash
# Instalar repositorio EA4 (si tienes cPanel) o Remi
# Para servidores con cPanel:
dnf install -y ea-php83 ea-php83-php-fpm ea-php83-php-mysqlnd \
  ea-php83-php-xml ea-php83-php-mbstring ea-php83-php-curl \
  ea-php83-php-zip ea-php83-php-gd ea-php83-php-opcache \
  ea-php83-php-pdo

# Habilitar y arrancar PHP-FPM
systemctl enable ea-php83-php-fpm
systemctl start ea-php83-php-fpm
```

**Para servidores SIN cPanel:**

```bash
# Instalar repositorio Remi
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf module reset php
dnf module enable php:remi-8.3 -y

# Instalar PHP
dnf install -y php php-fpm php-mysqlnd php-xml php-mbstring \
  php-curl php-zip php-gd php-opcache php-pdo php-cli

# Habilitar PHP-FPM
systemctl enable php-fpm
systemctl start php-fpm
```

### 2.4 Instalar MySQL 8.0

```bash
# Instalar MySQL
dnf install -y mysql-server

# Iniciar y habilitar
systemctl enable mysqld
systemctl start mysqld

# Asegurar instalación
mysql_secure_installation
```

**Respuestas recomendadas:**
- Remove anonymous users? **Yes**
- Disallow root login remotely? **Yes**
- Remove test database? **Yes**
- Reload privilege tables? **Yes**

### 2.5 Instalar Nginx

```bash
# Instalar Nginx
dnf install -y nginx

# Habilitar (NO iniciar aún)
systemctl enable nginx
```

### 2.6 Instalar Composer

```bash
cd /tmp
wget https://getcomposer.org/download/latest-stable/composer.phar
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer
composer --version
```

### 2.7 Instalar Node.js y npm

```bash
# Instalar Node.js 20
curl -fsSL https://rpm.nodesource.com/setup_20.x | bash -
dnf install -y nodejs
node --version
npm --version
```

### 2.8 Configurar Firewall

```bash
# Abrir puertos necesarios
firewall-cmd --permanent --add-service=http
firewall-cmd --permanent --add-service=https
firewall-cmd --permanent --add-service=ssh
firewall-cmd --reload

# Verificar
firewall-cmd --list-all
```

---

## 🎯 PASO 3: Configurar Base de Datos

### 3.1 Crear Base de Datos y Usuario

```bash
mysql -u root -p
```

```sql
-- Crear base de datos
CREATE DATABASE controlcd_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Crear usuario (genera un password seguro)
CREATE USER 'controlcd_prod'@'localhost' IDENTIFIED BY 'TU_PASSWORD_SEGURO';
CREATE USER 'controlcd_prod'@'127.0.0.1' IDENTIFIED BY 'TU_PASSWORD_SEGURO';

-- Otorgar permisos
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'localhost';
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'127.0.0.1';

-- Aplicar cambios
FLUSH PRIVILEGES;

-- Verificar
SHOW DATABASES;
SELECT User, Host FROM mysql.user WHERE User = 'controlcd_prod';

EXIT;
```

**Guardar las credenciales de forma segura.**

---

## 🎯 PASO 4: Configurar PHP-FPM para ControCD

### 4.1 Crear Pool de PHP-FPM

**Para servidores con cPanel (ea-php83):**

```bash
nano /opt/cpanel/ea-php83/root/etc/php-fpm.d/controlcd-prod.conf
```

**Para servidores sin cPanel:**

```bash
nano /etc/php-fpm.d/controlcd-prod.conf
```

**Contenido:**

```ini
[controlcd-prod]
user = nginx
group = nginx
listen = /var/run/php83-fpm-controlcd.sock
listen.owner = nginx
listen.group = nginx
listen.mode = 0660
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500
```

### 4.2 Reiniciar PHP-FPM

```bash
# Con cPanel
systemctl restart ea-php83-php-fpm

# Sin cPanel
systemctl restart php-fpm

# Verificar socket
ls -la /var/run/php83-fpm-controlcd.sock
```

---

## 🎯 PASO 5: Desplegar Backend

### 5.1 Crear Directorio del Proyecto

```bash
mkdir -p /var/www/controlcd-api
chown -R nginx:nginx /var/www/controlcd-api
```

### 5.2 Actualizar Script de Deploy Local

```bash
# En tu máquina local
cd /home/mario-d-az/git/ControCD-Backend
nano deploy-to-server.sh
```

**Actualizar variables:**

```bash
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_prod_controlcd"
SERVER_USER="root"
SERVER_IP="TU_IP_PRODUCCION"
SERVER_PATH="/var/www/controlcd-api"
LOCAL_PATH="/home/mario-d-az/git/ControCD-Backend"
```

### 5.3 Desplegar desde Local

```bash
cd /home/mario-d-az/git/ControCD-Backend

# Asegurar que .env está configurado para producción
cat .env | grep APP_ENV  # Debe mostrar: APP_ENV=production

# Desplegar
./deploy-to-server.sh
```

### 5.4 Configurar Backend en el Servidor

```bash
# Conectar al servidor
ssh controlcd-prod

cd /var/www/controlcd-api

# Instalar dependencias (puede tardar)
composer install --no-dev --optimize-autoloader

# Generar APP_KEY si no existe
php artisan key:generate

# Generar claves de Passport
php artisan passport:keys

# Ejecutar migraciones
php artisan migrate --force

# Ejecutar seeders si los tienes
php artisan db:seed --force

# Limpiar y cachear configuraciones
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Configurar permisos
chown -R nginx:nginx /var/www/controlcd-api
chmod -R 755 /var/www/controlcd-api
chmod -R 775 /var/www/controlcd-api/storage
chmod -R 775 /var/www/controlcd-api/bootstrap/cache

# Configurar SELinux
chcon -R -t httpd_sys_content_t /var/www/controlcd-api
chcon -R -t httpd_sys_rw_content_t /var/www/controlcd-api/storage
chcon -R -t httpd_sys_rw_content_t /var/www/controlcd-api/bootstrap/cache
```

---

## 🎯 PASO 6: Configurar Nginx

### 6.1 Detener Apache si está corriendo

```bash
# Verificar si Apache está usando los puertos
lsof -i :80 -i :443

# Si Apache está corriendo, detenerlo
systemctl stop httpd
systemctl disable httpd
```

### 6.2 Crear Configuración de Nginx

```bash
nano /etc/nginx/conf.d/controlcd-prod.conf
```

**Contenido (HTTP inicial, SSL después):**

```nginx
# Backend API - HTTP
server {
    listen 80;
    server_name api.control-cd.com;
    root /var/www/controlcd-api/public;
    
    index index.php index.html;
    
    client_max_body_size 100M;
    
    access_log /var/log/nginx/controlcd-api-access.log;
    error_log /var/log/nginx/controlcd-api-error.log;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php83-fpm-controlcd.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "upload_max_filesize=100M \n post_max_size=100M";
    }
    
    location ~ /\. {
        deny all;
    }
    
    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}

# Frontend App - HTTP
server {
    listen 80;
    server_name app.control-cd.com;
    root /var/www/controlcd-app;
    
    index index.html;
    
    access_log /var/log/nginx/controlcd-app-access.log;
    error_log /var/log/nginx/controlcd-app-error.log;
    
    location / {
        try_files $uri $uri/ /index.html;
    }
    
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires max;
        log_not_found off;
        add_header Cache-Control "public, immutable";
    }
    
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss application/json application/javascript;
}
```

### 6.3 Verificar y Arrancar Nginx

```bash
# Verificar configuración
nginx -t

# Iniciar Nginx
systemctl start nginx

# Verificar estado
systemctl status nginx
```

---

## 🎯 PASO 7: Instalar SSL/HTTPS

### 7.1 Instalar Certbot

```bash
dnf install -y certbot python3-certbot-nginx
```

### 7.2 Obtener Certificados

```bash
# Para ambos dominios
certbot certonly --nginx \
  -d api.control-cd.com \
  -d app.control-cd.com \
  --email admin@control-cd.com \
  --agree-tos \
  --non-interactive
```

### 7.3 Actualizar Nginx para HTTPS

```bash
nano /etc/nginx/conf.d/controlcd-prod.conf
```

**Reemplazar todo el contenido con:**

```nginx
# Backend API - HTTPS
server {
    listen 443 ssl http2;
    server_name api.control-cd.com;
    root /var/www/controlcd-api/public;
    
    index index.php index.html;
    
    client_max_body_size 100M;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/api.control-cd.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.control-cd.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    
    access_log /var/log/nginx/controlcd-api-access.log;
    error_log /var/log/nginx/controlcd-api-error.log;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php83-fpm-controlcd.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "upload_max_filesize=100M \n post_max_size=100M";
    }
    
    location ~ /\. {
        deny all;
    }
    
    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}

# Backend API - HTTP redirect to HTTPS
server {
    listen 80;
    server_name api.control-cd.com;
    return 301 https://$server_name$request_uri;
}

# Frontend App - HTTPS
server {
    listen 443 ssl http2;
    server_name app.control-cd.com;
    root /var/www/controlcd-app;
    
    index index.html;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/api.control-cd.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.control-cd.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    
    access_log /var/log/nginx/controlcd-app-access.log;
    error_log /var/log/nginx/controlcd-app-error.log;
    
    location / {
        try_files $uri $uri/ /index.html;
    }
    
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires max;
        log_not_found off;
        add_header Cache-Control "public, immutable";
    }
    
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss application/json application/javascript;
}

# Frontend App - HTTP redirect to HTTPS
server {
    listen 80;
    server_name app.control-cd.com;
    return 301 https://$server_name$request_uri;
}
```

### 7.4 Recargar Nginx

```bash
nginx -t
systemctl reload nginx
```

---

## 🎯 PASO 8: Desplegar Frontend

### 8.1 Crear Directorio

```bash
mkdir -p /var/www/controlcd-app
chown -R nginx:nginx /var/www/controlcd-app
```

### 8.2 Compilar y Desplegar desde Local

```bash
# En tu máquina local
cd /home/mario-d-az/git/ControCD-FrontEnd

# Verificar configuración
cat .quasar.env.json | grep -A 6 "production"

# Actualizar deploy-frontend.sh con datos de producción
nano deploy-frontend.sh
```

**Actualizar variables en el script:**

```bash
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_prod_controlcd"
SERVER_IP="TU_IP_PRODUCCION"
SERVER_PATH="/var/www/controlcd-app"
```

**Desplegar:**

```bash
./deploy-frontend.sh
# Seleccionar opción 2 (Production)
```

O manualmente:

```bash
# Instalar dependencias
npm install

# Compilar para producción
npx cross-env QENV=production quasar build

# Subir archivos
rsync -avzP --delete \
  -e "ssh -i ~/.ssh/id_rsa_prod_controlcd" \
  dist/spa/ \
  root@TU_IP_PRODUCCION:/var/www/controlcd-app/
```

### 8.3 Configurar Permisos en el Servidor

```bash
# En el servidor
chown -R nginx:nginx /var/www/controlcd-app
chmod -R 755 /var/www/controlcd-app
chcon -R -t httpd_sys_content_t /var/www/controlcd-app
```

---

## 🎯 PASO 9: Verificación Final

### 9.1 Verificar Backend

```bash
# Test de API
curl -I https://api.control-cd.com

# Test de login
curl -X POST https://api.control-cd.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"test","timezone":"America/Caracas"}'
```

### 9.2 Verificar Frontend

```bash
curl -I https://app.control-cd.com
```

### 9.3 Verificar CORS

```bash
curl -I -X OPTIONS \
  -H "Origin: https://app.control-cd.com" \
  -H "Access-Control-Request-Method: POST" \
  https://api.control-cd.com/api/login
```

Debe mostrar:
```
access-control-allow-origin: https://app.control-cd.com
```

### 9.4 Verificar en Navegador

1. Abre https://app.control-cd.com
2. Intenta hacer login
3. Verifica DevTools > Network
4. No debe haber errores CORS ni 500

---

## 🎯 PASO 10: Configuraciones Post-Despliegue

### 10.1 Crear Usuario Admin

```bash
# En el servidor
cd /var/www/controlcd-api

php artisan tinker --execute="
\$user = new App\Models\User();
\$user->name = 'Super Admin';
\$user->email = 'admin@control-cd.com';
\$user->password = Hash::make('TU_PASSWORD_SEGURO');
\$user->save();
echo 'Usuario creado!';
"
```

### 10.2 Configurar Renovación Automática de SSL

```bash
# Verificar cron de certbot
systemctl status certbot-renew.timer

# O manualmente agregar a crontab
crontab -e
```

Agregar:
```
0 3 * * * certbot renew --quiet && systemctl reload nginx
```

### 10.3 Configurar Backups de Base de Datos

```bash
# Crear script de backup
nano /root/backup-db.sh
```

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/root/backups/mysql"
mkdir -p $BACKUP_DIR

mysqldump -u controlcd_prod -p'TU_PASSWORD' controlcd_prod | gzip > $BACKUP_DIR/controlcd_prod_$DATE.sql.gz

# Mantener solo últimos 7 días
find $BACKUP_DIR -name "controlcd_prod_*.sql.gz" -mtime +7 -delete
```

```bash
chmod +x /root/backup-db.sh

# Agregar a crontab (diario a las 2 AM)
crontab -e
```

Agregar:
```
0 2 * * * /root/backup-db.sh
```

---

## 📊 Checklist Final

### Backend
- [ ] PHP 8.3 instalado y funcionando
- [ ] MySQL 8.0 instalado y seguro
- [ ] Composer instalado
- [ ] Base de datos creada con usuario
- [ ] `.env` configurado correctamente
- [ ] Migraciones ejecutadas
- [ ] Passport keys generadas
- [ ] Permisos correctos en storage/
- [ ] SELinux configurado
- [ ] PHP-FPM pool creado
- [ ] Nginx configurado
- [ ] SSL/HTTPS activo
- [ ] API responde correctamente

### Frontend
- [ ] Node.js y npm instalados
- [ ] `.quasar.env.json` configurado
- [ ] Build de producción creado
- [ ] Archivos subidos al servidor
- [ ] Permisos correctos
- [ ] Nginx sirviendo archivos
- [ ] HTTPS activo
- [ ] Frontend carga correctamente

### Seguridad
- [ ] Firewall configurado (solo 80, 443, 22)
- [ ] SELinux habilitado
- [ ] MySQL root remoto deshabilitado
- [ ] Apache/httpd deshabilitado
- [ ] SSL activo y renovación automática
- [ ] Backups configurados

### Funcionalidad
- [ ] Login funciona
- [ ] CORS sin errores
- [ ] No hay errores 500 en logs
- [ ] Frontend se conecta al backend
- [ ] Todas las rutas funcionan

---

## 🆘 Troubleshooting

### Error: "502 Bad Gateway"

```bash
# Verificar PHP-FPM
systemctl status ea-php83-php-fpm  # o php-fpm
systemctl restart ea-php83-php-fpm

# Verificar socket
ls -la /var/run/php83-fpm-controlcd.sock

# Ver logs
tail -f /var/log/nginx/controlcd-api-error.log
```

### Error: "Permission denied" en storage

```bash
cd /var/www/controlcd-api
chown -R nginx:nginx storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chcon -R -t httpd_sys_rw_content_t storage bootstrap/cache
```

### Error: CORS duplicado

```bash
# Nginx NO debe agregar headers CORS
# Laravel los maneja en config/cors.php
# Revisar configuración de Nginx y eliminar headers add_header Access-Control-*
```

### Error: "Connection refused" MySQL

```bash
# Verificar usuario tiene permisos desde localhost y 127.0.0.1
mysql -u root -p

SELECT User, Host FROM mysql.user WHERE User = 'controlcd_prod';

# Si falta, agregar:
GRANT ALL PRIVILEGES ON controlcd_prod.* TO 'controlcd_prod'@'127.0.0.1' IDENTIFIED BY 'PASSWORD';
FLUSH PRIVILEGES;
```

---

## 📞 Información de Contacto

**Documentación creada:** Noviembre 2025  
**Basada en:** Despliegue exitoso en Staging  
**Servidor Staging:** 146.190.147.164

---

## ✅ Resultado Esperado

Al finalizar todos los pasos deberías tener:

- 🌐 **Frontend:** https://app.control-cd.com
- 🔌 **API:** https://api.control-cd.com
- 🔐 **SSL:** Certificados válidos
- ✅ **CORS:** Funcionando sin errores
- ✅ **Base de datos:** Conectada y funcionando
- ✅ **Login:** Operativo

**🎊 ¡Tu aplicación ControCD estará lista para producción!** 🎊
