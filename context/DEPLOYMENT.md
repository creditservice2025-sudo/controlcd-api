# 🚀 Guía de Despliegue - ControCD Backend

Documentación completa para desplegar el backend en diferentes ambientes.

---

## 📋 Workflow Recomendado

### **Flujo de Trabajo:**

```
LOCAL (desarrollo) → STAGING (pruebas) → PRODUCTION (producción)
```

---

## 🔧 Configuración del Ambiente

### **1. Configurar .env en tu máquina local**

Usa el script helper:

```bash
./setup-env.sh
```

O manualmente:

**Para Staging:**
```bash
# Crear .env basado en el template
cp .env.staging.example .env

# Editar valores importantes
nano .env
```

Configura estos valores:
```env
APP_ENV=staging
APP_URL=https://staging-api.control-cd.com
DB_DATABASE=controlcd_db
DB_USERNAME=controlcd_user
DB_PASSWORD=tu_password_seguro
```

**IMPORTANTE:** El `.env` NO debe estar en Git. Solo los `.env.example` se versionan.

---

## 🚀 Desplegar al Servidor

### **Opción 1: Script Automatizado** ⭐ (Recomendado)

```bash
# 1. Asegúrate de tener el .env configurado
./setup-env.sh  # Si aún no lo has hecho

# 2. Despliega
./deploy-to-server.sh
```

Este script:
- ✅ Sube archivos al servidor (incluyendo .env)
- ✅ Instala dependencias con Composer
- ✅ Ejecuta migraciones
- ✅ Limpia y cachea configuraciones
- ✅ Configura permisos

### **Opción 2: Manual**

```bash
# 1. Subir archivos
rsync -avzP --delete \
  -e "ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd" \
  --exclude='vendor/' --exclude='node_modules/' \
  /home/mario-d-az/git/ControCD-Backend/ \
  root@146.190.147.164:/var/www/controlcd-api/

# 2. Conectarse al servidor
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164

# 3. Ejecutar en el servidor
cd /var/www/controlcd-api
composer install --optimize-autoloader --no-dev
php artisan key:generate  # Solo la primera vez
php artisan passport:keys  # Solo la primera vez
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 🌐 Configurar Dominios

### **1. Configurar DNS**

En tu proveedor de dominios (GoDaddy, Namecheap, etc.):

| Tipo | Nombre | Valor | TTL |
|------|--------|-------|-----|
| A | staging-api | 146.190.147.164 | 3600 |

### **2. Subir scripts de configuración al servidor**

```bash
scp -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd \
  -r server-setup/ \
  root@146.190.147.164:~/
```

### **3. Ejecutar en el servidor**

```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164

cd ~/server-setup
chmod +x *.sh

# Configurar dominio
./setup-domain.sh

# Instalar SSL
./install-ssl.sh
```

### **4. Actualizar .env con HTTPS**

En tu máquina local:

```bash
nano .env
```

Cambia:
```env
APP_URL=https://staging-api.control-cd.com
```

Despliega de nuevo:
```bash
./deploy-to-server.sh
```

---

## 🔄 Actualizar la Aplicación

Para actualizaciones posteriores:

```bash
# 1. Hacer tus cambios en el código

# 2. Si modificaste el .env, actualízalo localmente

# 3. Desplegar
./deploy-to-server.sh
```

---

## 🗂️ Estructura de Archivos de Ambiente

```
ControCD-Backend/
├── .env                        # Tu configuración actual (NO en git)
├── .env.example               # Template para desarrollo local (SÍ en git)
├── .env.staging.example       # Template para staging (SÍ en git)
├── setup-env.sh              # Script helper para configurar .env
├── deploy-to-server.sh       # Script de despliegue
└── server-setup/             # Scripts de configuración del servidor
    ├── 00-setup-all.sh
    ├── 01-install-dependencies.sh
    ├── 02-setup-database.sh
    ├── 03-setup-backend.sh
    ├── 04-setup-nginx.sh
    ├── 05-deploy-frontend.sh
    ├── setup-domain.sh
    ├── install-ssl.sh
    └── nginx-staging.conf
```

---

## ✅ Checklist de Primer Despliegue

- [ ] Configurar DNS (registro A)
- [ ] Subir scripts al servidor: `scp -r server-setup/ ...`
- [ ] Ejecutar instalación de dependencias: `./01-install-dependencies.sh`
- [ ] Configurar base de datos: `./02-setup-database.sh`
- [ ] Configurar .env localmente: `./setup-env.sh`
- [ ] Desplegar backend: `./deploy-to-server.sh`
- [ ] Configurar dominio en servidor: `./setup-domain.sh`
- [ ] Instalar SSL: `./install-ssl.sh`
- [ ] Actualizar .env a HTTPS localmente
- [ ] Re-desplegar: `./deploy-to-server.sh`
- [ ] Verificar: https://staging-api.control-cd.com

---

## 🔍 Verificación

```bash
# Probar API
curl https://staging-api.control-cd.com

# Ver logs en el servidor
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
tail -f /var/log/nginx/controlcd-api-error.log
```

---

## 🛠️ Troubleshooting

### Error 500
```bash
# En el servidor, revisar logs
cd /var/www/controlcd-api
tail -f storage/logs/laravel.log
```

### Permisos
```bash
# En el servidor
cd /var/www/controlcd-api
sudo chown -R nginx:nginx storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Cache
```bash
# En el servidor
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

---

## 📞 Información del Servidor

- **IP**: 146.190.147.164
- **Usuario**: root
- **SSH Key**: `/home/mario-d-az/.ssh/id_rsa_mario_controlcd`
- **Backend Path**: `/var/www/controlcd-api`
- **Frontend Path**: `/var/www/controlcd-app`
- **Dominio API**: staging-api.control-cd.com
- **Dominio App**: staging.control-cd.com
