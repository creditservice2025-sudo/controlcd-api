# Configuración de Crons - ControCD Backend

## 📋 Comandos de Liquidación

### **Comandos Automáticos (Cron)**

El proyecto tiene **3 comandos cron** configurados en `app/Console/Kernel.php`:

### 1. **Liquidación Automática Diaria** (`liquidation:auto-daily`)
- **Horario:** Diariamente a las 23:55 (11:55 PM)
- **Propósito:** Genera liquidación diaria automática para todos los vendedores si no existe
- **Archivo:** `app/Console/Commands/AutoLiquidateSellers.php`
- **Qué hace:**
  - Verifica cada vendedor
  - Si no existe liquidación para el día actual, la crea automáticamente
  - Calcula: cobros, gastos, ingresos, créditos nuevos, renovaciones, cartera irrecuperable
  - Estado: `auto`

### 2. **Liquidación Histórica** (`liquidation:historical`)
- **Horario:** Diariamente a las 23:55 (11:55 PM)
- **Propósito:** Crea liquidaciones históricas para vendedores con auto-cierre activado
- **Archivo:** `app/Console/Commands/CreateHistoricalLiquidation.php`
- **Qué hace:**
  - Solo procesa vendedores con `auto_closures_collectors = true` en su config
  - Genera liquidaciones desde la primera actividad del vendedor hasta ayer
  - Salta domingos automáticamente
  - No duplica liquidaciones existentes
  - Estado: `historical`

### 3. **Notificación de Liquidaciones Pendientes** (`liquidation:notify-pending`)
- **Horario:** Diariamente a las 21:52 (9:52 PM)
- **Propósito:** Notifica a administradores si vendedores no han generado su liquidación
- **Archivo:** `app/Console/Commands/NotifyPendingLiquidationSellers.php`
- **Qué hace:**
  - Verifica todos los vendedores
  - Si no tienen liquidación del día actual, notifica a administradores (role_id = 1)
  - Mensaje: "Faltan minutos para cierre diario"
  - Incluye enlace a `/dashboard/liquidaciones`

### **Comando Manual (Ejecución bajo demanda)**

### **4. Liquidación de Fecha Específica** (`liquidation:date`)
- **Ejecución:** MANUAL - cuando lo necesites
- **Propósito:** Genera liquidación para una fecha específica (útil para reprocesar días anteriores)
- **Archivo:** `app/Console/Commands/LiquidateSpecificDate.php`
- **Sintaxis:**
  ```bash
  php artisan liquidation:date [fecha] [--seller=ID]
  ```
- **Ejemplos:**
  ```bash
  # Generar liquidación de AYER para todos los vendedores
  php artisan liquidation:date
  
  # Generar liquidación de una fecha específica para todos
  php artisan liquidation:date 2025-11-19
  
  # Generar liquidación de ayer solo para vendedor ID 5
  php artisan liquidation:date --seller=5
  
  # Generar liquidación de fecha específica para vendedor específico
  php artisan liquidation:date 2025-11-19 --seller=5
  ```
- **Qué hace:**
  - Si no especificas fecha, usa AYER por defecto
  - Puedes especificar una fecha en formato YYYY-MM-DD
  - Puedes filtrar por un vendedor específico con `--seller=ID`
  - No duplica liquidaciones (verifica si ya existe)
  - Muestra resumen de liquidaciones creadas vs omitidas
  - Estado: `manual`

---

## Configuración en el Servidor de Producción

### **Servidor:** 146.190.147.164 (cPanel con ea-php83)
### **Path del Proyecto:** `/var/www/controlcd-api`

### **1. Configuración del Crontab**

Conectarse al servidor:
```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
```

Editar el crontab del usuario correcto:
```bash
crontab -u staging -e
```

**⚠️ IMPORTANTE:** El usuario debe ser `staging` ya que PHP-FPM corre como `staging:staging`

Agregar la siguiente línea al crontab:
```cron
* * * * * cd /var/www/controlcd-api && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Explicación:**
- `* * * * *` = Ejecuta cada minuto
- `cd /var/www/controlcd-api` = Cambia al directorio del proyecto
- `/opt/cpanel/ea-php83/root/usr/bin/php` = Ruta completa de PHP 8.3 en cPanel
- `artisan schedule:run` = Comando de Laravel que ejecuta los comandos programados
- `>> /dev/null 2>&1` = Redirige output a /dev/null (opcional, puedes cambiar por un log)

### **2. Verificar la Configuración de PHP**

Verificar que Laravel usa PHP 8.3:
```bash
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan --version
```

### **3. Verificar los Comandos Disponibles**

Listar todos los comandos artisan:
```bash
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan list
```

Verificar que aparezcan:
- `liquidation:auto-daily`
- `liquidation:historical`
- `liquidation:notify-pending`

---

## Pruebas Manuales

### **Ejecutar comandos manualmente para probar:**

```bash
# Conectarse al servidor
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd /var/www/controlcd-api

# Ejecutar liquidación automática diaria (HOY)
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:auto-daily

# Ejecutar liquidación histórica
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:historical

# Ejecutar notificación de pendientes
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:notify-pending

# ⭐ NUEVO: Ejecutar liquidación de AYER (más común)
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date

# Ejecutar liquidación de fecha específica
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date 2025-11-19

# Ejecutar liquidación de ayer solo para vendedor 5
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date --seller=5

# Ver el schedule completo
/opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:list
```

---

## 📊 Monitoreo de Crons

### **1. Ver logs del cron (si configuraste logging)**

Si cambias la línea del crontab para loguear:
```cron
* * * * * cd /var/www/controlcd-api && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /var/www/controlcd-api/storage/logs/cron.log 2>&1
```

Ver logs:
```bash
tail -f /var/www/controlcd-api/storage/logs/cron.log
```

### **2. Ver logs de Laravel**

```bash
tail -f /var/www/controlcd-api/storage/logs/laravel.log
```

### **3. Verificar que el cron está corriendo**

```bash
# Ver crontab configurado
crontab -u staging -l

# Ver procesos de artisan
ps aux | grep artisan

# Ver últimos crons ejecutados (sistema)
grep CRON /var/log/syslog | tail -20
```

---

## 🔧 Troubleshooting

### **Problema: El cron no ejecuta**

1. **Verificar permisos:**
```bash
cd /var/www/controlcd-api
chown -R staging:staging storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

2. **Verificar que el crontab está configurado:**
```bash
crontab -u staging -l
```

3. **Verificar logs del sistema:**
```bash
grep CRON /var/log/syslog | tail -50
```

### **Problema: Errores de timezone**

Los comandos usan `America/Lima` hardcodeado. Si necesitas cambiar a `America/Bogota`:

1. Editar cada comando en:
   - `app/Console/Commands/AutoLiquidateSellers.php` (línea 18)
   - `app/Console/Commands/CreateHistoricalLiquidation.php` (línea 22)
   - `app/Console/Commands/NotifyPendingLiquidationSellers.php` (línea 19)

2. Cambiar: `$timezone = 'America/Lima';` → `$timezone = 'America/Bogota';`

3. Re-deploy al servidor

### **Problema: Permisos denegados**

```bash
# Dar permisos al directorio del proyecto
chown -R staging:staging /var/www/controlcd-api
chmod -R 755 /var/www/controlcd-api

# Permisos especiales para storage y cache
chmod -R 775 /var/www/controlcd-api/storage
chmod -R 775 /var/www/controlcd-api/bootstrap/cache
```

---

## ⏰ Resumen de Horarios

| Comando | Horario | Descripción |
|---------|---------|-------------|
| `liquidation:notify-pending` | 21:52 (9:52 PM) | Notifica liquidaciones pendientes |
| `liquidation:auto-daily` | 23:55 (11:55 PM) | Genera liquidaciones automáticas |
| `liquidation:historical` | 23:55 (11:55 PM) | Genera liquidaciones históricas |

**Nota:** Los comandos a las 23:55 corren simultáneamente, pero no interfieren entre sí ya que:
- `auto-daily` solo crea liquidaciones si NO existen para hoy
- `historical` solo procesa vendedores con auto-cierre activado y hasta ayer

---

## 📝 Notas Importantes

1. **Timezone:** Los comandos usan `America/Lima` actualmente. Considera cambiar a `America/Bogota` si es necesario.

2. **Permisos:** El usuario del cron DEBE ser `staging` ya que PHP-FPM corre como `staging:staging`.

3. **SELinux:** Si tienes problemas de permisos en storage, ejecutar:
```bash
chcon -R -t httpd_sys_rw_content_t /var/www/controlcd-api/storage
```

4. **Logs:** Los comandos generan output con `$this->info()`, verifica `storage/logs/laravel.log`.

5. **Testing:** SIEMPRE prueba los comandos manualmente antes de confiar en el cron.

6. **Notificaciones:** El comando `notify-pending` requiere que el sistema de notificaciones de Laravel esté configurado correctamente.

---

## 🔄 Comandos Rápidos

```bash
# Ver schedule programado
php artisan schedule:list

# Ejecutar el schedule ahora (para testing)
php artisan schedule:run

# Ver todos los comandos disponibles
php artisan list

# Ejecutar comandos automáticos (cron)
php artisan liquidation:auto-daily         # Liquidación de HOY
php artisan liquidation:historical         # Liquidaciones históricas
php artisan liquidation:notify-pending     # Notificar pendientes

# ⭐ Ejecutar comando manual (fecha específica)
php artisan liquidation:date               # Liquidación de AYER (default)
php artisan liquidation:date 2025-11-19    # Liquidación de fecha específica
php artisan liquidation:date --seller=5    # Solo vendedor ID 5
```
