# 🔧 Fix: Problemas de Login por Permisos OAuth

## 🐛 Problema

Después de actualizar el backend con `deploy-to-server.sh`, el **login deja de funcionar** y se generan errores:

```
The resource owner or authorization server denied the request.
OAuthServerException: Access token ha...
```

## 🔍 Causa Raíz

Las **OAuth keys de Laravel Passport** (`oauth-private.key` y `oauth-public.key`) tienen **permisos incorrectos** después del deploy:

- **Problema:** Las keys se copian con permisos `nginx:nginx` o `root:root`
- **Requerido:** Las keys deben tener permisos `staging:staging` porque PHP-FPM corre como ese usuario

## ✅ Solución Implementada

Se actualizó el script `deploy-to-server.sh` para:

### **1. Excluir OAuth keys del rsync**

Las keys NO se sobrescriben desde local, se mantienen las del servidor:

```bash
--exclude='storage/oauth-*.key' \
```

### **2. Corregir permisos automáticamente después de cada deploy**

```bash
echo "→ Configurando permisos (CRÍTICO para evitar errores 500)..."
# Permisos generales de storage y cache
chown -R staging:staging storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chmod -R 664 storage/logs/*.log 2>/dev/null || true
chmod 775 storage/logs

# IMPORTANTE: Corregir permisos de OAuth keys (siempre después de rsync)
echo "  → Corrigiendo permisos de OAuth Passport keys..."
if [ -f storage/oauth-private.key ]; then
    chown staging:staging storage/oauth-private.key storage/oauth-public.key
    chmod 600 storage/oauth-private.key
    chmod 644 storage/oauth-public.key
    echo "  ✓ OAuth keys: permisos corregidos"
else
    echo "  ⚠ OAuth keys no encontradas, ejecuta: php artisan passport:keys"
fi
```

### **3. Limpiar cache antes y después**

```bash
# Antes
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Después de permisos
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### **4. Reiniciar PHP-FPM automáticamente**

```bash
systemctl restart ea-php83-php-fpm
```

---

## 🚀 Uso

Ahora simplemente ejecuta:

```bash
cd /home/mario-d-az/git/ControCD-Backend
./deploy-to-server.sh
```

**Los permisos se corregirán automáticamente** ✅

---

## 🔧 Corrección Manual (si es necesario)

Si por alguna razón necesitas corregir los permisos manualmente:

```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164

cd /var/www/controlcd-api

# Corregir permisos de OAuth keys
chown staging:staging storage/oauth-private.key storage/oauth-public.key
chmod 600 storage/oauth-private.key
chmod 644 storage/oauth-public.key

# Corregir permisos de storage
chown -R staging:staging storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Reiniciar PHP-FPM
systemctl restart ea-php83-php-fpm

# Limpiar cache
cd /var/www/controlcd-api
php artisan cache:clear
php artisan config:clear
```

---

## 📋 Verificación

### **1. Verificar permisos de las keys:**

```bash
ls -la /var/www/controlcd-api/storage/oauth-*.key
```

**Output esperado:**
```
-rw-------. 1 staging staging 3318 Nov 15 00:11 oauth-private.key
-rw-r--r--. 1 staging staging  812 Nov 15 00:11 oauth-public.key
```

### **2. Verificar que PHP-FPM está corriendo:**

```bash
systemctl status ea-php83-php-fpm
```

### **3. Verificar logs:**

```bash
tail -f /var/www/controlcd-api/storage/logs/laravel.log
```

**Buscar que NO haya errores de OAuth:**
```bash
grep -i "oauth" /var/www/controlcd-api/storage/logs/laravel.log | tail -20
```

---

## 🔑 Permisos Correctos Completos

### **OAuth Keys**
```bash
-rw-------  staging:staging  oauth-private.key  (600)
-rw-r--r--  staging:staging  oauth-public.key   (644)
```

### **Storage**
```bash
drwxrwxr-x  staging:staging  storage/           (775)
drwxrwxr-x  staging:staging  storage/logs/      (775)
-rw-rw-r--  staging:staging  storage/logs/*.log (664)
```

### **Bootstrap Cache**
```bash
drwxrwxr-x  staging:staging  bootstrap/cache/   (775)
```

---

## 📝 Notas Importantes

1. **Usuario PHP-FPM:** El usuario `staging:staging` es CRÍTICO porque PHP-FPM corre con ese usuario en cPanel
2. **No commit de keys:** Las OAuth keys están en `.gitignore` y NO se deben versionar
3. **Keys en servidor:** Las keys se generan UNA VEZ en el servidor con `php artisan passport:keys`
4. **Invalidación de tokens:** Si regeneras las keys, todos los tokens existentes se invalidan y los usuarios deben hacer login nuevamente

---

## 🔄 Regenerar Keys (Solo si es Necesario)

⚠️ **ADVERTENCIA:** Esto invalidará TODOS los tokens de acceso existentes.

```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd /var/www/controlcd-api

# Regenerar keys
php artisan passport:keys --force

# Corregir permisos
chown staging:staging storage/oauth-*.key
chmod 600 storage/oauth-private.key
chmod 644 storage/oauth-public.key

# Reiniciar PHP-FPM
systemctl restart ea-php83-php-fpm
```

---

## 📚 Referencias

- **Script de deploy:** `/home/mario-d-az/git/ControCD-Backend/deploy-to-server.sh`
- **Documentación Passport:** `context/PASSPORT_SETUP.md`
- **Usuario PHP-FPM:** `staging:staging` (cPanel con ea-php83)
- **Servidor:** 146.190.147.164

---

**Última actualización:** 2025-11-20  
**Problema resuelto:** ✅ Permisos OAuth se corrigen automáticamente en cada deploy  
**Autor:** Mario Díaz
