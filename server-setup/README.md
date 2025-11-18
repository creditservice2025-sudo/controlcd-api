# Scripts de Configuración del Servidor - ControCD

Scripts automatizados para configurar el servidor AlmaLinux para la aplicación ControCD.

## 📋 Requisitos Previos

- Servidor con **AlmaLinux** instalado
- Acceso **SSH** con permisos **sudo**
- Dominios configurados apuntando al servidor
- Firewall abierto para puertos 80 y 443

## 🚀 Instalación Rápida

### Opción 1: Instalación Automática Completa

```bash
# Subir los scripts al servidor
scp -r server-setup/ usuario@tu-servidor:~/

# Conectarse al servidor
ssh usuario@tu-servidor

# Navegar a la carpeta
cd ~/server-setup

# Dar permisos de ejecución
chmod +x *.sh

# Ejecutar el script maestro
./00-setup-all.sh
```

### Opción 2: Instalación Paso a Paso

Si prefieres más control, ejecuta los scripts uno por uno:

```bash
# 1. Instalar dependencias (PHP, MySQL, Nginx, Node.js, Composer)
./01-install-dependencies.sh

# 2. Configurar base de datos MySQL
./02-setup-database.sh

# 3. Configurar el backend Laravel
./03-setup-backend.sh

# 4. Configurar Nginx y virtual hosts
./04-setup-nginx.sh

# 5. Configurar despliegue del frontend
./05-deploy-frontend.sh
```

## 📝 Descripción de los Scripts

### `00-setup-all.sh`
Script maestro que ejecuta todos los demás scripts en orden. Ideal para configuración inicial.

### `01-install-dependencies.sh`
- Actualiza el sistema
- Instala repositorios EPEL y Remi
- Instala PHP 8.1 y todas sus extensiones necesarias
- Instala MySQL 8.0
- Instala Nginx
- Instala Composer
- Instala Node.js 20
- Configura firewall y SELinux

### `02-setup-database.sh`
- Crea la base de datos de ControCD
- Crea el usuario de base de datos
- Configura permisos
- Guarda la configuración para los siguientes scripts

### `03-setup-backend.sh`
- Crea la estructura de directorios
- Configura el archivo `.env` de Laravel
- Instala dependencias con Composer
- Genera claves de aplicación y Passport
- Ejecuta migraciones
- Configura permisos y SELinux

### `04-setup-nginx.sh`
- Configura virtual hosts para API y Frontend
- Configura PHP-FPM
- Optimiza configuración para producción
- Habilita compresión gzip

### `05-deploy-frontend.sh`
- Prepara el directorio del frontend
- Configura permisos
- Proporciona instrucciones para despliegue

## 🔧 Configuración Post-Instalación

### 1. Subir el Código del Backend

Desde tu máquina local:

```bash
# Opción A: Usando rsync
rsync -avz --exclude 'vendor' --exclude 'node_modules' \
  /home/mario-d-az/git/ControCD-Backend/ \
  usuario@servidor:/var/www/controlcd-api/

# Opción B: Usando Git
ssh usuario@servidor
cd /var/www/controlcd-api
git clone https://tu-repo.git .
```

### 2. Desplegar el Frontend

```bash
# En tu máquina local, en el proyecto frontend
cd /home/mario-d-az/git/ControCD-FrontEnd

# Actualizar la URL de la API en .env.production
# VITE_API_URL=https://api.tudominio.com/api

# Compilar
npm run build

# Subir al servidor
rsync -avz dist/spa/ usuario@servidor:/var/www/controlcd-app/
```

### 3. Instalar Certificados SSL

```bash
# En el servidor
sudo dnf install certbot python3-certbot-nginx -y

# Obtener certificados para todos tus dominios
sudo certbot --nginx \
  -d tudominio.com \
  -d www.tudominio.com \
  -d api.tudominio.com
```

### 4. Configurar GitLab CI/CD

En GitLab, ve a **Settings > CI/CD > Variables** y agrega:

**Para el Backend:**
- `SSH_HOST_PROD`: IP o dominio del servidor
- `SSH_USER_PROD`: usuario SSH
- `SSH_PASSWORD_PROD`: contraseña SSH
- `SERVER_PATH_PROD`: `/var/www/controlcd-api`
- `SSH_COMMAND_PROD`:
  ```bash
  cd /var/www/controlcd-api && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && sudo chown -R nginx:nginx storage bootstrap/cache
  ```

**Para el Frontend:**
- `SSH_HOST_PROD`: IP o dominio del servidor
- `SSH_USER_PROD`: usuario SSH
- `SSH_PASSWORD_PROD`: contraseña SSH
- `SERVER_PATH_PROD`: `/var/www/controlcd-app`

## 🔍 Verificación

### Verificar servicios

```bash
# Estado de Nginx
sudo systemctl status nginx

# Estado de PHP-FPM
sudo systemctl status php-fpm

# Estado de MySQL
sudo systemctl status mysqld

# Ver logs de Nginx
sudo tail -f /var/log/nginx/controlcd-api-error.log
sudo tail -f /var/log/nginx/controlcd-app-error.log
```

### Verificar aplicación

```bash
# Verificar que el backend responde
curl -I http://api.tudominio.com

# Verificar que el frontend responde
curl -I http://tudominio.com

# Verificar permisos
ls -la /var/www/controlcd-api/storage
ls -la /var/www/controlcd-app
```

## 🐛 Solución de Problemas

### Error 502 Bad Gateway

```bash
# Verificar que PHP-FPM está corriendo
sudo systemctl status php-fpm

# Verificar socket de PHP-FPM
ls -la /run/php-fpm/www.sock

# Reiniciar PHP-FPM
sudo systemctl restart php-fpm
```

### Error de permisos en Laravel

```bash
cd /var/www/controlcd-api
sudo chown -R nginx:nginx storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chcon -R -t httpd_sys_rw_content_t storage bootstrap/cache
```

### SELinux bloqueando conexiones

```bash
# Ver logs de SELinux
sudo tail -f /var/log/audit/audit.log

# Habilitar permisos necesarios
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_unified 1
sudo setsebool -P httpd_execmem 1
```

## 📞 Soporte

Si encuentras problemas:

1. Revisa los logs: `/var/log/nginx/` y `/var/log/php-fpm/`
2. Verifica el estado de los servicios con `systemctl status`
3. Consulta los logs de SELinux: `/var/log/audit/audit.log`

## 📄 Licencia

Scripts de configuración para uso interno del proyecto ControCD.
