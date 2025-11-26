# 🔧 Guía Rápida: Liquidación Manual de Fechas Específicas

## ✨ Comando Nuevo: `liquidation:date`

Este comando te permite generar liquidaciones para fechas anteriores sin necesidad de usar el cron.

---

## 📝 Sintaxis

```bash
php artisan liquidation:date [fecha] [--seller=ID]
```

**Parámetros:**
- `[fecha]` - Opcional. Formato: YYYY-MM-DD. Si no se especifica, usa AYER.
- `[--seller=ID]` - Opcional. ID del vendedor. Si no se especifica, procesa TODOS los vendedores.

---

## 🚀 Casos de Uso Comunes

### **1. Generar liquidación de AYER para todos los vendedores**

```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date
```

**Output esperado:**
```
No se especificó fecha. Usando fecha de AYER: 2025-11-19
Generando liquidaciones para: 2025-11-19
Procesando TODOS los vendedores (15 total)
  ✓ Vendedor 1 (Juan Pérez): Liquidación creada | Real a entregar: $5000
  ✓ Vendedor 2 (María García): Liquidación creada | Real a entregar: $3200
  ⚠ Vendedor 3 (Carlos López): Ya existe liquidación para 2025-11-19
  ...

═══════════════════════════════════════
  Resumen para 2025-11-19:
  ✓ Creadas: 12
  ⚠ Omitidas (ya existían): 3
═══════════════════════════════════════
```

---

### **2. Generar liquidación de una fecha específica**

Si olvidaste generar la liquidación del 15 de noviembre:

```bash
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date 2025-11-15
```

---

### **3. Generar liquidación de ayer solo para un vendedor**

Si un vendedor específico (ID 5) tuvo problemas y necesitas regenerar solo su liquidación:

```bash
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date --seller=5
```

---

### **4. Generar liquidación de fecha específica para un vendedor**

Regenerar liquidación del 10 de noviembre solo para el vendedor 8:

```bash
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date 2025-11-10 --seller=8
```

---

## 🛡️ Protecciones del Comando

### **1. No Duplica Liquidaciones**
- Si ya existe una liquidación para la fecha especificada, el comando la OMITE
- Esto evita duplicados accidentales

### **2. Validación de Fecha**
- Si introduces una fecha inválida, te muestra un error:
  ```
  Formato de fecha inválido. Use: YYYY-MM-DD (ejemplo: 2025-11-19)
  ```

### **3. Validación de Vendedor**
- Si el vendedor no existe, te muestra un error:
  ```
  Vendedor con ID 99 no encontrado
  ```

### **4. Estado "manual"**
- Las liquidaciones creadas con este comando tienen `status = 'manual'`
- Esto las diferencia de las automáticas (`auto`) e históricas (`historical`)

---

## 📊 Diferencias entre Comandos

| Comando | Fecha | Vendedores | Estado | Cuándo Usar |
|---------|-------|------------|--------|-------------|
| `liquidation:auto-daily` | HOY | Todos | `auto` | Cron automático (23:55) |
| `liquidation:historical` | Desde inicio hasta ayer | Solo con auto-cierre | `historical` | Cron automático (23:55) |
| `liquidation:date` | Personalizada (default: AYER) | Todos o uno específico | `manual` | Cuando necesites reprocesar |
| `liquidation:notify-pending` | HOY | Todos | N/A | Cron automático (21:52) |

---

## 💡 Casos de Uso Reales

### **Caso 1: El servidor estuvo caído ayer**

Si el servidor estuvo inactivo ayer y no se generaron las liquidaciones:

```bash
# Generar todas las liquidaciones de ayer
php artisan liquidation:date
```

---

### **Caso 2: Un vendedor reporta error en su liquidación**

Si un vendedor dice que su liquidación del 12 de noviembre está mal:

```bash
# 1. Eliminar la liquidación incorrecta (desde la base de datos o admin panel)
# 2. Regenerar solo para ese vendedor
php artisan liquidation:date 2025-11-12 --seller=7
```

---

### **Caso 3: Reprocesar liquidaciones de una semana completa**

Si necesitas regenerar liquidaciones de varios días:

```bash
# Ejecutar para cada día (o crear un script)
php artisan liquidation:date 2025-11-13
php artisan liquidation:date 2025-11-14
php artisan liquidation:date 2025-11-15
php artisan liquidation:date 2025-11-16
php artisan liquidation:date 2025-11-17
```

O crear un script bash:

```bash
#!/bin/bash
for date in 2025-11-13 2025-11-14 2025-11-15 2025-11-16 2025-11-17; do
    echo "Procesando: $date"
    /opt/cpanel/ea-php83/root/usr/bin/php artisan liquidation:date $date
    echo "---"
done
```

---

### **Caso 4: Reprocesar todos los vendedores de ayer excepto uno**

Si necesitas regenerar ayer pero un vendedor ya tiene su liquidación correcta:

```bash
# El comando automáticamente omite los que ya existen
php artisan liquidation:date

# Output mostrará:
#   ✓ Vendedor 1: Liquidación creada
#   ⚠ Vendedor 5: Ya existe liquidación (el que querías mantener)
#   ✓ Vendedor 8: Liquidación creada
```

---

## 🔍 Verificación Post-Ejecución

Después de ejecutar el comando, verifica en:

### **1. Laravel Log**
```bash
tail -f /var/www/controlcd-api/storage/logs/laravel.log
```

### **2. Base de Datos**
```sql
-- Ver liquidaciones creadas hoy con status manual
SELECT * FROM liquidations 
WHERE status = 'manual' 
AND created_at >= CURDATE() 
ORDER BY created_at DESC;

-- Ver liquidaciones de una fecha específica
SELECT seller_id, date, real_to_deliver, status 
FROM liquidations 
WHERE date = '2025-11-19' 
ORDER BY seller_id;
```

### **3. Frontend**
- Accede al dashboard de liquidaciones
- Filtra por la fecha que procesaste
- Verifica que aparezcan las liquidaciones

---

## ⚠️ Notas Importantes

1. **Timezone:** El comando usa `America/Lima`. Si necesitas cambiar a `America/Bogota`, edita la línea 18 del archivo:
   ```php
   // En: app/Console/Commands/LiquidateSpecificDate.php
   $timezone = 'America/Bogota';  // Cambiar de Lima a Bogota
   ```

2. **No elimina liquidaciones existentes:** Solo crea nuevas. Si necesitas reemplazar una existente, primero elimínala manualmente.

3. **Initial Cash:** El comando obtiene el `initial_cash` de la liquidación anterior (ordenada por fecha).

4. **Permisos:** Asegúrate de ejecutar como root o con sudo si hay problemas de permisos.

---

## 🐛 Troubleshooting

### **Error: "Class 'App\Console\Commands\LiquidateSpecificDate' not found"**

**Solución:**
```bash
cd /var/www/controlcd-api
php artisan clear-compiled
composer dump-autoload
```

### **Error: "SQLSTATE[42S02]: Base table or view not found"**

**Causa:** La tabla `liquidations` no existe.

**Solución:** Ejecuta las migraciones:
```bash
php artisan migrate
```

### **No aparece el comando en la lista**

**Verificar:**
```bash
php artisan list | grep liquidation
```

Si no aparece `liquidation:date`, ejecuta:
```bash
composer dump-autoload
php artisan cache:clear
```

---

## 📚 Referencias

- **Archivo del Comando:** `app/Console/Commands/LiquidateSpecificDate.php`
- **Documentación Completa:** `context/CRON_CONFIGURATION.md`
- **Configuración de Crons:** `app/Console/Kernel.php`

---

**Última actualización:** 2025-11-20  
**Autor:** Mario Díaz  
**Versión:** 1.0.0
