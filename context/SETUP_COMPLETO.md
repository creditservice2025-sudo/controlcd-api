# 📚 Documentación Completa - Setup ControCD

Documentación paso a paso de la configuración completa del proyecto ControCD en el servidor AlmaLinux.

---

## 📋 Información del Proyecto

### **Servidor**
- **IP**: 146.190.147.164
- **OS**: AlmaLinux
- **Usuario**: root
- **SSH Key**: `/home/mario-d-az/.ssh/id_rsa_mario_controlcd`

### **Dominios**
- **Backend API**: staging-api.control-cd.com
- **Frontend App**: staging.control-cd.com

### **Rutas en el Servidor**
- **Backend**: `/var/www/controlcd-api`
- **Frontend**: `/var/www/controlcd-app`

### **Rutas Locales**
- **Backend**: `/home/mario-d-az/git/ControCD-Backend`
- **Frontend**: `/home/mario-d-az/git/ControCD-FrontEnd`

---

## 🏗️ Arquitectura del Sistema

```
┌─────────────────────────────────────────────┐
│           Usuario (Navegador)               │
└────────────────┬────────────────────────────┘
                 │
                 │ HTTPS (443)
                 │
┌────────────────▼────────────────────────────┐
│         Nginx (Reverse Proxy)               │
│  - staging.control-cd.com (Frontend)        │
│  - staging-api.control-cd.com (Backend)     │
└────────┬───────────────────┬────────────────┘
         │                   │
         │                   │
    ┌────▼────┐         ┌────▼────────┐
    │ Frontend│         │  Backend    │
    │  Quasar │         │  Laravel    │
    │   SPA   │         │  PHP 8.1    │
    └─────────┘         └─────┬───────┘
                              │
                         ┌────▼────┐
                         │  MySQL  │
                         │   8.0   │
                         └─────────┘
```

---

## 🚀 Proceso de Instalación Completo

### **Fase 1: Configuración del Servidor**

#### 1.1 Instalación de Dependencias

```bash
# En el servidor
cd ~/server-setup
./01-install-dependencies.sh
```

**Instala:**
- PHP 8.1 + extensiones (mysql, curl, xml, mbstring, etc.)
- MySQL 8.0
- Nginx
- Composer
- Node.js 20
- npm

**También configura:**
- Firewall (puertos 80, 443)
- SELinux (permisos para httpd)
- Servicios iniciados y habilitados

#### 1.2 Configuración de Base de Datos

```bash
# En el servidor
./02-setup-database.sh
```

**Crea:**
- Base de datos: `controlcd_db`
- Usuario: `controlcd_user`
- Permisos completos en la base de datos

#### 1.3 Configuración DNS

**En el proveedor de dominios (GoDaddy, Namecheap, etc.):**

| Tipo | Nombre | Valor | TTL |
|------|--------|-------|-----|
| A | staging-api | 146.190.147.164 | 3600 |
| A | staging | 146.190.147.164 | 3600 |

**Verificar propagación:**
```bash
dig +short staging-api.control-cd.com
dig +short staging.control-cd.com
```

#### 1.4 Configuración de Nginx

```bash
# En el servidor
cd ~/server-setup
./setup-domain.sh
```

**Configura:**
- Virtual hosts para API y Frontend
- PHP-FPM
- Compresión gzip
- Cache headers

**Archivos creados:**
- `/etc/nginx/conf.d/controlcd-staging.conf`

#### 1.5 Instalación de SSL

```bash
# En el servidor
cd ~/server-setup
./install-ssl.sh
```

**Instala:**
- Certbot
- Certificados SSL de Let's Encrypt
- Renovación automática
- Redirección HTTP → HTTPS

---

### **Fase 2: Configuración del Backend Laravel**

#### 2.1 Configurar `.env` Local

```bash
# En tu máquina local
cd /home/mario-d-az/git/ControCD-Backend

# Usar el script helper
./setup-env.sh
# Seleccionar opción 2 (Staging)
```

**O manualmente:**
```bash
cp .env.staging.example .env
nano .env
```

**Configuración importante:**
```env
APP_NAME=ControlCD
APP_ENV=staging
APP_URL=https://staging-api.control-cd.com
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=controlcd_db
DB_USERNAME=controlcd_user
DB_PASSWORD=tu_password_seguro
```

#### 2.2 Configurar CORS

**Archivo: `config/cors.php`**
```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],

'allowed_origins' => [
    'https://staging.control-cd.com',
    'https://staging-api.control-cd.com',
    'http://localhost:9000',
    'http://localhost:8080',
],

'supports_credentials' => true,
```

**Archivo: `app/Http/Kernel.php`**
```php
protected $middleware = [
    \Illuminate\Http\Middleware\HandleCors::class,
];
```

#### 2.3 Desplegar Backend

```bash
# En tu máquina local
cd /home/mario-d-az/git/ControCD-Backend
./deploy-to-server.sh
```

**El script automáticamente:**
- ✅ Sube archivos (incluido `.env`)
- ✅ Instala dependencias con Composer
- ✅ Ejecuta migraciones
- ✅ Cachea configuraciones
- ✅ Configura permisos
- ✅ Configura SELinux

#### 2.4 Primera Vez en el Servidor

```bash
# Solo la primera vez
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164

cd /var/www/controlcd-api

# Generar claves de aplicación
php artisan key:generate
php artisan passport:keys

# Ejecutar migraciones con seeds
php artisan migrate --seed --force
```

---

### **Fase 3: Configuración del Frontend Quasar**

#### 3.1 Configurar Variables de Entorno

**Archivo: `.quasar.env.json`**
```json
{
  "staging": {
    "ENV_TYPE": "prod",
    "API_URL": "https://staging-api.control-cd.com",
    "UPDATE_WEB_URL": "https://staging.control-cd.com",
    "GOOGLE_MAPS_API_KEY": "AIzaSyAoJdlcDryZp-G3bKYxcZLfaEtQCmGaftY"
  }
}
```

#### 3.2 Desplegar Frontend

```bash
# En tu máquina local
cd /home/mario-d-az/git/ControCD-FrontEnd
./deploy-frontend.sh
# Seleccionar opción 1 (Staging)
```

**El script automáticamente:**
- ✅ Instala dependencias con npm
- ✅ Compila con `QENV=staging`
- ✅ Sube `dist/spa/` al servidor
- ✅ Configura permisos
- ✅ Configura SELinux

---

## 🔧 Solución de Problemas Comunes

### **Problema: Error de CORS**

**Síntoma:**
```
Access-Control-Allow-Origin header is not present
```

**Solución:**

1. Verificar configuración de CORS en Laravel:
```bash
# En el servidor
cd /var/www/controlcd-api
cat config/cors.php
```

2. Limpiar cache:
```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

3. Agregar headers en Nginx:
```bash
sudo nano /etc/nginx/conf.d/controlcd-staging.conf
```

Agregar en el `location ~ \.php$`:
```nginx
location ~ \.php$ {
    # ... configuración existente ...
    
    # Headers CORS
    add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With' always;
    add_header 'Access-Control-Allow-Credentials' 'true' always;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
    
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Max-Age' 1728000;
        add_header 'Content-Type' 'text/plain charset=UTF-8';
        add_header 'Content-Length' 0;
        return 204;
    }
}
```

4. Reiniciar Nginx:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### **Problema: Error 500 en Backend**

**Solución:**
```bash
# Ver logs
sudo tail -f /var/log/nginx/controlcd-api-error.log
cd /var/www/controlcd-api
tail -f storage/logs/laravel.log

# Verificar permisos
sudo chown -R nginx:nginx storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chcon -R -t httpd_sys_rw_content_t storage bootstrap/cache
```

### **Problema: Error 502 Bad Gateway**

**Solución:**
```bash
# Verificar PHP-FPM
sudo systemctl status php-fpm
sudo systemctl restart php-fpm

# Verificar socket
ls -la /run/php-fpm/www.sock

# Ver logs
sudo tail -f /var/log/php-fpm/error.log
```

### **Problema: Puerto 80 en uso**

**Solución:**
```bash
# Detener Apache/httpd
sudo systemctl stop httpd
sudo systemctl disable httpd

# Reiniciar Nginx
sudo systemctl start nginx
```

### **Problema: Frontend no se actualiza**

**Solución:**
```bash
# Limpiar cache del navegador: Ctrl + Shift + R

# Recompilar y redesplegar
cd /home/mario-d-az/git/ControCD-FrontEnd
rm -rf dist/
./deploy-frontend.sh
```

---

## 📝 Comandos Útiles

### **Backend**

```bash
# Desplegar
./deploy-to-server.sh

# Ver logs en el servidor
ssh -i ~/.ssh/id_rsa_mario_controlcd root@146.190.147.164
tail -f /var/www/controlcd-api/storage/logs/laravel.log
tail -f /var/log/nginx/controlcd-api-error.log

# Limpiar cache
cd /var/www/controlcd-api
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Ejecutar migraciones
php artisan migrate --force

# Ver rutas
php artisan route:list
```

### **Frontend**

```bash
# Desplegar
./deploy-frontend.sh

# Compilar localmente
npx cross-env QENV=staging quasar build

# Ver archivos compilados
ls -la dist/spa/

# Desarrollo local
npm run dev
```

### **Servidor**

```bash
# Conectarse
ssh -i ~/.ssh/id_rsa_mario_controlcd root@146.190.147.164

# Ver servicios
sudo systemctl status nginx
sudo systemctl status php-fpm
sudo systemctl status mysqld

# Reiniciar servicios
sudo systemctl restart nginx
sudo systemctl restart php-fpm

# Ver logs de Nginx
sudo tail -f /var/log/nginx/access.log
sudo tail -f /var/log/nginx/error.log

# Ver logs de SELinux
sudo tail -f /var/log/audit/audit.log

# Ver puertos abiertos
sudo netstat -tlnp
sudo ss -tlnp
```

---

## 🔄 Workflow de Actualización

### **1. Actualizar Backend**

```bash
cd /home/mario-d-az/git/ControCD-Backend

# Hacer cambios en el código

# Si modificaste el .env, actualízalo localmente

# Desplegar
./deploy-to-server.sh
```

### **2. Actualizar Frontend**

```bash
cd /home/mario-d-az/git/ControCD-FrontEnd

# Hacer cambios en el código

# Si modificaste la URL de la API, actualiza .quasar.env.json

# Desplegar
./deploy-frontend.sh
```

### **3. Actualizar Base de Datos**

```bash
# En tu máquina local, crear migración
cd /home/mario-d-az/git/ControCD-Backend
php artisan make:migration nombre_de_la_migracion

# Editar la migración
# ...

# Desplegar
./deploy-to-server.sh

# En el servidor, las migraciones se ejecutan automáticamente
```

---

## 📊 Checklist de Despliegue

### **Primera Vez**

- [ ] Configurar DNS (registros A)
- [ ] Subir scripts de setup al servidor
- [ ] Ejecutar `01-install-dependencies.sh`
- [ ] Ejecutar `02-setup-database.sh`
- [ ] Configurar `.env` del backend localmente
- [ ] Desplegar backend con `./deploy-to-server.sh`
- [ ] Generar `APP_KEY` y `passport:keys` en el servidor
- [ ] Ejecutar migraciones en el servidor
- [ ] Ejecutar `./setup-domain.sh` en el servidor
- [ ] Actualizar `.quasar.env.json` del frontend
- [ ] Desplegar frontend con `./deploy-frontend.sh`
- [ ] Ejecutar `./install-ssl.sh` en el servidor
- [ ] Actualizar URLs a HTTPS en `.env` y `.quasar.env.json`
- [ ] Re-desplegar backend y frontend
- [ ] Verificar aplicación: https://staging.control-cd.com
- [ ] Verificar API: https://staging-api.control-cd.com

### **Actualizaciones Posteriores**

- [ ] Hacer cambios en el código
- [ ] Actualizar configuraciones si es necesario
- [ ] Desplegar con scripts correspondientes
- [ ] Verificar que todo funcione correctamente

---

## 🔐 Seguridad

### **Recomendaciones Implementadas**

- ✅ SSL/TLS con Let's Encrypt
- ✅ Firewall configurado (solo puertos 80, 443, 22)
- ✅ SELinux habilitado y configurado
- ✅ Permisos restrictivos en archivos
- ✅ `.env` no versionado en Git
- ✅ CORS configurado restrictivamente
- ✅ Credenciales de BD seguras

### **Recomendaciones Adicionales**

- 🔒 Usar fail2ban para proteger SSH
- 🔒 Configurar rate limiting en Laravel
- 🔒 Revisar logs periódicamente
- 🔒 Mantener sistema actualizado
- 🔒 Backups automáticos de base de datos

---

## 📞 Información de Contacto y Recursos

### **Documentación**

- Backend: `/home/mario-d-az/git/ControCD-Backend/DEPLOYMENT.md`
- Frontend: `/home/mario-d-az/git/ControCD-FrontEnd/DEPLOYMENT.md`
- Este archivo: `/home/mario-d-az/git/ControCD-Backend/SETUP_COMPLETO.md`

### **Enlaces Útiles**

- Laravel Docs: https://laravel.com/docs
- Quasar Docs: https://quasar.dev
- AlmaLinux Docs: https://wiki.almalinux.org
- Let's Encrypt: https://letsencrypt.org

---

## 📅 Historial de Cambios

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2025-11-18 | Setup inicial del servidor staging | Setup automatizado |
| 2025-11-18 | Configuración de CORS | Setup automatizado |
| 2025-11-18 | Instalación de SSL | Setup automatizado |

---

## ✅ Resumen

Has configurado exitosamente:

1. ✅ Servidor AlmaLinux con todas las dependencias
2. ✅ Backend Laravel con API REST
3. ✅ Frontend Quasar SPA
4. ✅ Base de datos MySQL
5. ✅ Nginx como reverse proxy
6. ✅ SSL/HTTPS con Let's Encrypt
7. ✅ Scripts de despliegue automatizado
8. ✅ Configuración de CORS
9. ✅ SELinux y Firewall

**URLs Finales:**
- 🌐 Frontend: https://staging.control-cd.com
- 🔌 API: https://staging-api.control-cd.com

**¡Felicitaciones! Tu aplicación está lista para usar.** 🎉
