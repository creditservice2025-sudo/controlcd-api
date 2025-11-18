# 🔧 Solución de Problema de CORS - ControCD

Guía paso a paso para solucionar el error de CORS actual.

---

## 🚨 Problema Actual

```
Access to XMLHttpRequest at 'https://staging-api.control-cd.com/api/login'
from origin 'https://staging.control-cd.com' has been blocked by CORS policy:
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

---

## ✅ Solución Paso a Paso

### **Paso 1: Desplegar Backend con Configuración de CORS**

```bash
# En tu máquina local
cd /home/mario-d-az/git/ControCD-Backend

# Desplegar (esto subirá los archivos actualizados de CORS)
./deploy-to-server.sh
```

**Esto subirá:**
- `config/cors.php` (actualizado)
- `app/Http/Kernel.php` (actualizado)

---

### **Paso 2: Agregar Headers de CORS en Nginx**

```bash
# Conectarse al servidor
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164

# Editar configuración de Nginx
sudo nano /etc/nginx/conf.d/controlcd-staging.conf
```

**Reemplazar el bloque completo del servidor de la API con esto:**

```nginx
# Backend API
server {
    listen 80;
    server_name staging-api.control-cd.com;
    root /var/www/controlcd-api/public;
    
    index index.php index.html;
    
    client_max_body_size 100M;
    
    access_log /var/log/nginx/controlcd-api-access.log;
    error_log /var/log/nginx/controlcd-api-error.log;
    
    # Manejar peticiones OPTIONS (preflight)
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Max-Age' 86400 always;
        add_header 'Content-Type' 'text/plain charset=UTF-8';
        add_header 'Content-Length' 0;
        return 204;
    }
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
        
        # Headers CORS para todas las respuestas
        add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token' always;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "upload_max_filesize=100M \n post_max_size=100M";
        
        # Headers CORS para respuestas PHP
        add_header 'Access-Control-Allow-Origin' 'https://staging.control-cd.com' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, PATCH, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token' always;
    }
    
    location ~ /\.(?!well-known).* {
        deny all;
    }
    
    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

**Guardar:** `Ctrl + O`, `Enter`, `Ctrl + X`

---

### **Paso 3: Verificar y Reiniciar Nginx**

```bash
# Verificar sintaxis de Nginx
sudo nginx -t

# Si todo está OK, reiniciar Nginx
sudo systemctl reload nginx
```

---

### **Paso 4: Limpiar Cache de Laravel**

```bash
# En el servidor
cd /var/www/controlcd-api

# Limpiar toda la cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Volver a cachear configuraciones
php artisan config:cache
```

---

### **Paso 5: Verificar Configuración de CORS en Laravel**

```bash
# Ver configuración de CORS
cat config/cors.php

# Debe mostrar algo como:
# 'allowed_origins' => [
#     'https://staging.control-cd.com',
#     'https://staging-api.control-cd.com',
#     ...
# ],
```

---

### **Paso 6: Limpiar Cache del Navegador**

En tu navegador:
1. Abre DevTools (F12)
2. Clic derecho en el botón de recargar
3. Selecciona "Empty Cache and Hard Reload" (Vaciar caché y recargar)

O simplemente: **Ctrl + Shift + R** (Cmd + Shift + R en Mac)

---

## 🧪 Pruebas

### **Test 1: Verificar Headers de CORS**

```bash
# Desde tu terminal local
curl -I -X OPTIONS \
  -H "Origin: https://staging.control-cd.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  https://staging-api.control-cd.com/api/login
```

**Deberías ver:**
```
Access-Control-Allow-Origin: https://staging.control-cd.com
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS
Access-Control-Allow-Credentials: true
```

### **Test 2: Verificar Login desde el Navegador**

1. Abre: https://staging.control-cd.com
2. Abre DevTools (F12) → pestaña Network
3. Intenta hacer login
4. Verifica que no haya errores de CORS

---

## 🔍 Diagnóstico

Si el problema persiste, ejecuta estos comandos para diagnosticar:

```bash
# En el servidor

# 1. Ver logs de Nginx en tiempo real
sudo tail -f /var/log/nginx/controlcd-api-error.log

# 2. Ver logs de Laravel
cd /var/www/controlcd-api
tail -f storage/logs/laravel.log

# 3. Ver configuración actual de Nginx
sudo nginx -T | grep -A 50 "staging-api.control-cd.com"

# 4. Verificar que PHP-FPM esté corriendo
sudo systemctl status php-fpm

# 5. Ver variables de entorno de Laravel
cd /var/www/controlcd-api
php artisan config:show cors
```

---

## 📋 Checklist de Verificación

- [ ] Backend desplegado con configuración de CORS actualizada
- [ ] Nginx configurado con headers de CORS
- [ ] Nginx reiniciado sin errores
- [ ] Cache de Laravel limpiada
- [ ] Cache del navegador limpiada
- [ ] Test de CORS con curl exitoso
- [ ] Login desde navegador funciona

---

## ⚠️ Notas Importantes

### **CORS en Laravel vs Nginx**

Laravel maneja CORS automáticamente, pero a veces Nginx puede interferir o no pasar los headers correctamente. Por eso agregamos los headers directamente en Nginx.

### **Orden de Headers**

Los headers deben agregarse con `always` para que se incluyan incluso en respuestas de error (4xx, 5xx).

### **Preflight Requests**

Las peticiones OPTIONS son "preflight" - el navegador las envía antes de la petición real para verificar si el servidor permite CORS.

---

## 🆘 Si Nada Funciona

### **Opción 1: CORS Permisivo (Solo para Testing)**

**ADVERTENCIA:** Esto permite peticiones de cualquier origen. NO usar en producción.

```bash
# En el servidor
sudo nano /etc/nginx/conf.d/controlcd-staging.conf
```

Cambiar `add_header 'Access-Control-Allow-Origin'` a:
```nginx
add_header 'Access-Control-Allow-Origin' '*' always;
```

### **Opción 2: Verificar Certificado SSL**

```bash
# Verificar certificado
openssl s_client -connect staging-api.control-cd.com:443 -showcerts

# Verificar fecha de expiración
echo | openssl s_client -connect staging-api.control-cd.com:443 2>/dev/null | openssl x509 -noout -dates
```

### **Opción 3: Revisar Logs Completos**

```bash
# Ver últimos 100 errores de Nginx
sudo tail -100 /var/log/nginx/error.log

# Ver últimos 100 accesos
sudo tail -100 /var/log/nginx/access.log

# Ver logs de PHP
sudo tail -100 /var/log/php-fpm/error.log
```

---

## 📞 Contacto

Si después de seguir todos los pasos el problema persiste, revisa:

1. La configuración de DNS
2. El certificado SSL
3. Las reglas del firewall
4. Los logs del servidor

---

## ✅ Resultado Esperado

Después de aplicar todos los pasos, deberías poder:

- ✅ Abrir https://staging.control-cd.com
- ✅ Ver el formulario de login
- ✅ Hacer login sin errores de CORS
- ✅ Ver la respuesta de la API en DevTools

**Fecha de creación:** 2025-11-18  
**Estado:** Pendiente de aplicar
