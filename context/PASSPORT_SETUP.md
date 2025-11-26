# 🔐 Configuración de Laravel Passport

## 📋 Resumen

Esta guía documenta la configuración de Laravel Passport para autenticación OAuth2 en el backend de ControCD.

---

## 🔑 Componentes de Passport

### 1. APP_KEY (Laravel)
**Qué es:** Clave de cifrado de Laravel para sesiones, cookies, y datos cifrados.

**Generación automática:** ✅ El script de deploy la genera si no existe.

**Ubicación:** `.env`
```bash
APP_KEY=base64:XNZONjBTONhwVtM+rCi3Nn2T7aGHEwk2yqQ4s6R9Reg=
```

### 2. Passport Keys (OAuth)
**Qué son:** Par de llaves pública/privada para firmar tokens JWT.

**Generación automática:** ✅ El script de deploy las genera si no existen.

**Ubicación:** 
```
storage/oauth-private.key  (3.3 KB, permisos: 600)
storage/oauth-public.key   (812 bytes, permisos: 644)
```

**Owner:** `staging:staging` (crítico para que PHP-FPM pueda leerlas)

### 3. OAuth Clients
**Qué son:** Aplicaciones autorizadas para usar el API con OAuth2.

**Generación:** ❌ MANUAL (solo una vez por ambiente)

---

## 🚀 Configuración Inicial (Primera Vez)

### Paso 1: Deploy del Backend

El script de deploy automáticamente:
1. ✅ Genera `APP_KEY` si no existe
2. ✅ Genera `oauth-private.key` y `oauth-public.key`
3. ✅ Configura permisos correctos

```bash
cd /home/mario-d-az/git/ControCD-FrontEnd
./deploy-staging-api.sh
```

### Paso 2: Crear OAuth Clients (MANUAL)

**Conectarse al servidor:**
```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd /var/www/controlcd-api
```

**Crear Personal Access Client:**
```bash
php artisan passport:client --personal \
  --name='ControCD Personal Access Client' \
  --no-interaction
```

**Output esperado:**
```
Client ID: 2
Client secret: HkZ7GhrE747YeAMYH9blGh33XU6qDfZZX5hwuPcB
```

**Crear Password Grant Client:**
```bash
php artisan passport:client --password \
  --name='ControCD Password Grant Client' \
  --no-interaction
```

**Output esperado:**
```
Client ID: 3
Client secret: 6cVgxfioBcjWMNxOBiqawyTNDZ7j0Sjdv4XZ54go
```

---

## 📝 Tipos de OAuth Clients

### Personal Access Client
**Uso:** Tokens de acceso personal para usuarios.

**Cuándo usar:**
- Acceso programático por usuario
- CLIs o scripts
- Testing/debugging

**Ejemplo:**
```javascript
// Usuario genera su propio token
POST /oauth/personal-access-tokens
{
  "name": "Mi Token Personal",
  "scopes": []
}
```

### Password Grant Client
**Uso:** Login normal de usuarios (email + password).

**Cuándo usar:**
- Autenticación en frontend (web/móvil)
- Login estándar de usuarios
- La mayoría de los casos

**Ejemplo:**
```javascript
POST /oauth/token
{
  "grant_type": "password",
  "client_id": 3,
  "client_secret": "6cVgxfioBcjWMNxOBiqawyTNDZ7j0Sjdv4XZ54go",
  "username": "user@example.com",
  "password": "password123",
  "scope": "*"
}
```

---

## 🔧 Configuración en el Frontend

### Para Apps Híbridas (Quasar + Capacitor)

**Archivo:** `src/boot/axios.js` o similar

```javascript
// Configuración de Axios para Passport
axios.defaults.headers.common['Accept'] = 'application/json';

// Login con Password Grant
async function login(email, password) {
  try {
    const response = await axios.post('/oauth/token', {
      grant_type: 'password',
      client_id: process.env.PASSPORT_CLIENT_ID || 3,
      client_secret: process.env.PASSPORT_CLIENT_SECRET || '6cVgxfioBcjWMNxOBiqawyTNDZ7j0Sjdv4XZ54go',
      username: email,
      password: password,
      scope: '*'
    });
    
    const { access_token, refresh_token } = response.data;
    
    // Guardar tokens
    localStorage.setItem('access_token', access_token);
    localStorage.setItem('refresh_token', refresh_token);
    
    // Configurar header de autorización
    axios.defaults.headers.common['Authorization'] = `Bearer ${access_token}`;
    
    return response.data;
  } catch (error) {
    console.error('Login error:', error);
    throw error;
  }
}
```

---

## 🗄️ Base de Datos

### Tablas de Passport

Passport crea automáticamente estas tablas en la migración:

```
oauth_access_tokens
oauth_auth_codes
oauth_clients          ← Aquí se guardan los clients
oauth_personal_access_clients
oauth_refresh_tokens
```

### Permisos Requeridos

**Usuarios de base de datos:**
```sql
-- Debe tener permisos en la base de datos
GRANT ALL PRIVILEGES ON `controlcd-2`.* TO 'staging-controlcd'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `controlcd-2`.* TO 'andres_controlcd'@'%';
```

**Ya configurado:** ✅

---

## 🔄 Regenerar Keys (Si es Necesario)

### ⚠️ ADVERTENCIA
Regenerar las keys **INVALIDA TODOS LOS TOKENS EXISTENTES**. Los usuarios deberán hacer login nuevamente.

### Comando
```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd /var/www/controlcd-api

# Regenerar keys
php artisan passport:keys --force

# Corregir permisos
chown staging:staging storage/oauth-*.key
chmod 600 storage/oauth-private.key
chmod 644 storage/oauth-public.key

# Limpiar cache
php artisan config:cache
systemctl restart ea-php83-php-fpm
```

---

## 🐛 Troubleshooting

### Error: "Client authentication failed"

**Causa:** Client ID o Secret incorrectos

**Solución:**
1. Verificar que el cliente existe:
```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd /var/www/controlcd-api
php artisan passport:client --list
```

2. Verificar credenciales en el frontend

### Error: "The MAC is invalid"

**Causa:** APP_KEY cambió y hay datos cifrados con la key anterior

**Solución:**
1. Limpiar sesiones:
```bash
php artisan session:clear
php artisan cache:clear
```

2. Los usuarios deberán hacer login nuevamente

### Error: "Unable to read key from file"

**Causa:** Permisos incorrectos en `oauth-*.key`

**Solución:**
```bash
cd /var/www/controlcd-api
chown staging:staging storage/oauth-*.key
chmod 600 storage/oauth-private.key
chmod 644 storage/oauth-public.key
```

### Error: "Access denied to database"

**Causa:** Usuario de DB no tiene permisos en la base de datos

**Solución:**
```bash
mysql -e "GRANT ALL PRIVILEGES ON \`controlcd-2\`.* TO 'staging-controlcd'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

---

## 📊 Verificación Post-Configuración

### Checklist

- [ ] APP_KEY generado y en `.env`
- [ ] `oauth-private.key` existe con permisos 600
- [ ] `oauth-public.key` existe con permisos 644
- [ ] Owner de keys es `staging:staging`
- [ ] Personal Access Client creado (ID: 2)
- [ ] Password Grant Client creado (ID: 3)
- [ ] Permisos de base de datos correctos
- [ ] API responde con 401 (no 500) en `/api/login`

### Test Manual

```bash
# Debe retornar 401, no 500
curl -X POST https://staging-api.control-cd.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test","password":"test"}' \
  -i
```

**Respuesta esperada:**
```
HTTP/2 401
access-control-allow-origin: https://staging.control-cd.com
{
  "message": ["Los datos introducidos son inválidos"]
}
```

---

## 📚 Referencias

- [Laravel Passport Docs](https://laravel.com/docs/10.x/passport)
- [OAuth 2.0 Password Grant](https://oauth.net/2/grant-types/password/)
- [JWT Tokens](https://jwt.io/)

---

## 🔐 Seguridad

### Buenas Prácticas

1. **NUNCA** commitear el `.env` al repositorio
2. **NUNCA** exponer los client secrets en el frontend
3. **Usar HTTPS** siempre para OAuth
4. **Rotar keys** periódicamente en producción
5. **Expirar tokens** (configurado en `config/passport.php`)

### Variables de Entorno Seguras

```bash
# En .env del servidor (NO commitear)
PASSPORT_PERSONAL_ACCESS_CLIENT_ID=2
PASSPORT_PERSONAL_ACCESS_CLIENT_SECRET=HkZ7GhrE747YeAMYH9blGh33XU6qDfZZX5hwuPcB
PASSPORT_PASSWORD_GRANT_CLIENT_ID=3
PASSPORT_PASSWORD_GRANT_CLIENT_SECRET=6cVgxfioBcjWMNxOBiqawyTNDZ7j0Sjdv4XZ54go
```

---

## 📌 Credenciales Actuales (Staging)

**⚠️ SOLO PARA REFERENCIA - NO USAR EN PRODUCCIÓN**

```
Personal Access Client:
  ID: 2
  Secret: HkZ7GhrE747YeAMYH9blGh33XU6qDfZZX5hwuPcB

Password Grant Client:
  ID: 3
  Secret: 6cVgxfioBcjWMNxOBiqawyTNDZ7j0Sjdv4XZ54go
```

**Para producción:** Generar nuevos clients con secrets diferentes.

---

**Última actualización:** 18 Nov 2025  
**Ambiente:** Staging  
**Responsable:** Mario Díaz
