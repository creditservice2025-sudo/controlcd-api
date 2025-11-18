# 📚 ControCD - Documentación Completa del Proyecto

Bienvenido a la documentación completa del proyecto ControCD. Esta guía te ayudará a navegar por toda la documentación disponible.

---

## 📂 Estructura del Proyecto

```
/home/mario-d-az/git/
├── ControCD-Backend/          # Backend Laravel + API REST
│   ├── SETUP_COMPLETO.md      # 📘 Guía completa de setup
│   ├── DEPLOYMENT.md          # 🚀 Guía de despliegue backend
│   ├── FIX_CORS.md            # 🔧 Solución de problemas CORS
│   ├── deploy-to-server.sh    # Script de despliegue
│   ├── setup-env.sh           # Script de configuración .env
│   └── server-setup/          # Scripts de configuración del servidor
│       ├── 01-install-dependencies.sh
│       ├── 02-setup-database.sh
│       ├── 03-setup-backend.sh
│       ├── 04-setup-nginx.sh
│       ├── 05-deploy-frontend.sh
│       ├── setup-domain.sh
│       ├── install-ssl.sh
│       ├── fix-cors.sh
│       └── README.md
│
├── ControCD-FrontEnd/         # Frontend Quasar + Vue 3
│   ├── DEPLOYMENT.md          # 🎨 Guía de despliegue frontend
│   ├── deploy-frontend.sh     # Script de despliegue
│   └── .quasar.env.json       # Configuración de ambientes
│
└── README_PROYECTO.md         # 📚 Este archivo
```

---

## 📖 Guías de Documentación

### **🎯 Para Empezar**

1. **[SETUP_COMPLETO.md](ControCD-Backend/SETUP_COMPLETO.md)** - **COMIENZA AQUÍ**
   - Guía completa de configuración desde cero
   - Arquitectura del sistema
   - Instalación paso a paso
   - Solución de problemas comunes
   - Comandos útiles

### **🚀 Para Desplegar**

2. **[Backend DEPLOYMENT.md](ControCD-Backend/DEPLOYMENT.md)**
   - Configuración del backend Laravel
   - Manejo de archivos `.env`
   - Script de despliegue automatizado
   - Comandos de Artisan

3. **[Frontend DEPLOYMENT.md](ControCD-FrontEnd/DEPLOYMENT.md)**
   - Configuración del frontend Quasar
   - Manejo de `.quasar.env.json`
   - Compilación y despliegue
   - Configuración de ambientes

### **🔧 Para Solucionar Problemas**

4. **[FIX_CORS.md](ControCD-Backend/FIX_CORS.md)**
   - Solución específica para errores de CORS
   - Configuración de Nginx con headers CORS
   - Tests de verificación
   - Diagnóstico paso a paso

5. **[Scripts README.md](ControCD-Backend/server-setup/README.md)**
   - Documentación de scripts de servidor
   - Troubleshooting de servicios
   - Comandos de verificación

---

## ⚡ Quick Start

### **Primera Vez - Setup Completo**

```bash
# 1. Configurar DNS (en tu proveedor de dominios)
# Crear registros A:
#   staging-api → 146.190.147.164
#   staging → 146.190.147.164

# 2. Subir scripts al servidor
cd /home/mario-d-az/git/ControCD-Backend
scp -i ~/.ssh/id_rsa_mario_controlcd -r server-setup/ root@146.190.147.164:~/

# 3. Conectar al servidor y ejecutar setup
ssh -i ~/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd ~/server-setup
chmod +x *.sh
./01-install-dependencies.sh
./02-setup-database.sh

# 4. Configurar y desplegar backend (en tu máquina local)
cd /home/mario-d-az/git/ControCD-Backend
./setup-env.sh  # Seleccionar opción 2 (Staging)
./deploy-to-server.sh

# 5. Configurar dominio (en el servidor)
cd ~/server-setup
./setup-domain.sh

# 6. Desplegar frontend (en tu máquina local)
cd /home/mario-d-az/git/ControCD-FrontEnd
./deploy-frontend.sh  # Seleccionar opción 1 (Staging)

# 7. Instalar SSL (en el servidor)
cd ~/server-setup
./install-ssl.sh

# 8. ¡Listo! Visita: https://staging.control-cd.com
```

### **Actualizaciones Posteriores**

```bash
# Backend
cd /home/mario-d-az/git/ControCD-Backend
./deploy-to-server.sh

# Frontend
cd /home/mario-d-az/git/ControCD-FrontEnd
./deploy-frontend.sh
```

---

## 🗺️ Flujo de Trabajo Recomendado

```
┌─────────────────────────────────────────┐
│  1. Desarrollo Local                    │
│     - Hacer cambios en el código       │
│     - Probar localmente                 │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│  2. Configuración                       │
│     - Actualizar .env si es necesario   │
│     - Actualizar .quasar.env.json       │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│  3. Despliegue                          │
│     - ./deploy-to-server.sh (backend)   │
│     - ./deploy-frontend.sh (frontend)   │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│  4. Verificación                        │
│     - Revisar logs                      │
│     - Probar funcionalidad              │
│     - Monitorear errores                │
└─────────────────────────────────────────┘
```

---

## 🌐 URLs del Proyecto

### **Staging (Actual)**
- **Frontend**: https://staging.control-cd.com
- **API**: https://staging-api.control-cd.com
- **Servidor**: 146.190.147.164

### **Producción (Futuro)**
- **Frontend**: https://controlcd.com
- **API**: https://api.controlcd.com

---

## 🔧 Scripts Disponibles

### **Backend**

```bash
# Configuración
./setup-env.sh              # Configurar archivo .env

# Despliegue
./deploy-to-server.sh       # Desplegar backend completo

# Servidor (ejecutar en el servidor)
cd ~/server-setup
./01-install-dependencies.sh  # Instalar PHP, MySQL, Nginx, etc.
./02-setup-database.sh        # Configurar MySQL
./03-setup-backend.sh         # Configurar backend
./04-setup-nginx.sh           # Configurar Nginx
./setup-domain.sh             # Configurar dominio
./install-ssl.sh              # Instalar certificados SSL
./fix-cors.sh                 # Solucionar problemas CORS
```

### **Frontend**

```bash
# Despliegue
./deploy-frontend.sh        # Compilar y desplegar frontend

# Desarrollo
npm run dev                 # Desarrollo local
npm run build               # Compilar para producción
```

---

## 📊 Stack Tecnológico

### **Backend**
- **Framework**: Laravel 11
- **Lenguaje**: PHP 8.1
- **Base de Datos**: MySQL 8.0
- **Autenticación**: Laravel Passport (OAuth2)
- **Servidor Web**: Nginx + PHP-FPM

### **Frontend**
- **Framework**: Quasar 2 (Vue 3)
- **Lenguaje**: JavaScript/TypeScript
- **Build Tool**: Vite
- **UI**: Quasar Components

### **Infraestructura**
- **Servidor**: AlmaLinux
- **SSL**: Let's Encrypt (Certbot)
- **Firewall**: firewalld
- **SELinux**: Enabled

---

## 🔐 Seguridad

### **Implementado**
- ✅ SSL/TLS (HTTPS)
- ✅ Firewall configurado
- ✅ SELinux habilitado
- ✅ CORS restrictivo
- ✅ `.env` no versionado
- ✅ Permisos de archivos restrictivos

### **Recomendaciones Adicionales**
- 🔒 Implementar fail2ban
- 🔒 Configurar rate limiting
- 🔒 Monitoreo de logs
- 🔒 Backups automáticos

---

## 📞 Solución de Problemas

### **Error de CORS**
Ver: [FIX_CORS.md](ControCD-Backend/FIX_CORS.md)

### **Error 500 Backend**
```bash
# Ver logs
sudo tail -f /var/log/nginx/controlcd-api-error.log
cd /var/www/controlcd-api
tail -f storage/logs/laravel.log
```

### **Error 502 Bad Gateway**
```bash
# Verificar PHP-FPM
sudo systemctl status php-fpm
sudo systemctl restart php-fpm
```

### **Frontend no actualiza**
```bash
# Recompilar y redesplegar
cd /home/mario-d-az/git/ControCD-FrontEnd
rm -rf dist/
./deploy-frontend.sh
```

---

## 📝 Comandos Útiles

### **Conectarse al Servidor**
```bash
ssh -i ~/.ssh/id_rsa_mario_controlcd root@146.190.147.164
```

### **Ver Logs en Tiempo Real**
```bash
# Nginx
sudo tail -f /var/log/nginx/controlcd-api-error.log
sudo tail -f /var/log/nginx/controlcd-app-error.log

# Laravel
tail -f /var/www/controlcd-api/storage/logs/laravel.log

# PHP-FPM
sudo tail -f /var/log/php-fpm/error.log
```

### **Verificar Servicios**
```bash
sudo systemctl status nginx
sudo systemctl status php-fpm
sudo systemctl status mysqld
```

### **Limpiar Cache**
```bash
# Laravel
cd /var/www/controlcd-api
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Navegador
# Ctrl + Shift + R (Cmd + Shift + R en Mac)
```

---

## 📚 Referencias Externas

- [Laravel Documentation](https://laravel.com/docs)
- [Quasar Documentation](https://quasar.dev)
- [Vue 3 Documentation](https://vuejs.org)
- [AlmaLinux Wiki](https://wiki.almalinux.org)
- [Nginx Documentation](https://nginx.org/en/docs/)
- [Let's Encrypt](https://letsencrypt.org)

---

## 📅 Historial

| Fecha | Cambio | Estado |
|-------|--------|--------|
| 2025-11-18 | Setup inicial del servidor | ✅ Completado |
| 2025-11-18 | Instalación de dependencias | ✅ Completado |
| 2025-11-18 | Configuración de base de datos | ✅ Completado |
| 2025-11-18 | Despliegue de backend | ✅ Completado |
| 2025-11-18 | Configuración de dominios | ✅ Completado |
| 2025-11-18 | Despliegue de frontend | ✅ Completado |
| 2025-11-18 | Instalación de SSL | ✅ Completado |
| 2025-11-18 | Configuración de CORS | 🔄 En proceso |

---

## ✅ Checklist Final

### **Infraestructura**
- [x] Servidor AlmaLinux configurado
- [x] PHP 8.1 instalado
- [x] MySQL 8.0 instalado
- [x] Nginx instalado
- [x] Node.js 20 instalado
- [x] Composer instalado
- [x] Firewall configurado
- [x] SELinux configurado

### **Backend**
- [x] Laravel instalado
- [x] Base de datos creada
- [x] Migraciones ejecutadas
- [x] .env configurado
- [x] CORS configurado
- [x] Passport instalado

### **Frontend**
- [x] Quasar instalado
- [x] .quasar.env.json configurado
- [x] Build exitoso
- [x] Archivos desplegados

### **Dominios y SSL**
- [x] DNS configurado
- [x] Nginx virtual hosts configurados
- [x] SSL instalado
- [x] HTTPS funcionando

### **Pendiente**
- [ ] Resolver error de CORS (ejecutar fix-cors.sh)
- [ ] Configurar backups automáticos
- [ ] Configurar monitoreo
- [ ] Optimizar rendimiento

---

## 🎉 Estado Actual

**✅ Sistema Operativo:** Staging completamente configurado  
**🌐 Frontend:** https://staging.control-cd.com  
**🔌 API:** https://staging-api.control-cd.com  
**⚠️ Pendiente:** Solucionar CORS (ver FIX_CORS.md)

---

**Última actualización:** 2025-11-18  
**Mantenido por:** Mario Díaz  
**Versión:** 1.0.0
