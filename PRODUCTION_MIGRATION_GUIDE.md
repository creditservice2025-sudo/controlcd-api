# 🚀 Migración de Pagos en Cache - PRODUCCIÓN

## ⚠️ SCRIPT FINAL DE PRODUCCIÓN

Este es el script **COMPLETO** para migrar los pagos en cache de producción.

## 📊 Datos a Migrar

Según la extracción realizada:

-   **10 créditos** con abonos en cache
-   **11 pagos** totales
-   **Monto total**: $318

### Detalle:

| Credit ID | Cliente                | Pagos | Monto |
| --------- | ---------------------- | ----- | ----- |
| 527       | WALTER PENA CORNEJO    | 1     | $20   |
| 578       | LUIS FABIANO FIESTAS   | 1     | $9    |
| 698       | Rosa Ruiz pomalca      | 1     | $40   |
| 710       | LORENA STEFANY MELGAR  | 1     | $4    |
| 825       | Vania Yenifer chanduvi | 2     | $70   |
| 826       | JUAN CORTEZ HURTADO    | 1     | $40   |
| 835       | Margarita Ortiz Ruiz   | 1     | $70   |
| 856       | Susana calle Chiroque  | 1     | $20   |
| 918       | Angel Orlando Juarez   | 1     | $40   |
| 1023      | Jessica angeles patapo | 1     | $5    |

## 🎯 Uso

```bash
cd /home/mario-d-az/git/ControCD-Backend
./migrate-payment-cache-production.sh
```

## 🔒 Seguridad

El script tiene **MÚLTIPLES confirmaciones**:

1. ✅ Confirmación inicial
2. ✅ Segunda confirmación (escribir "SI")
3. ✅ Dry-run automático
4. ✅ Tercera confirmación antes de migrar (escribir "MIGRAR")

## 📋 Proceso Completo

### 1. Subir Comandos

-   ExtractCachedPayments.php
-   MigrateCachedPayments.php
-   VerifyPaymentMigration.php
-   AnalyzeMigratedPayments.php

### 2. Extraer Cache

-   Ejecuta `php artisan payments:extract-cached`
-   Crea backup JSON en servidor
-   Guarda en `~/backup_cache_production_*.json`

### 3. Aplicar Migraciones DB

-   Agrega columna `unapplied_amount`
-   Agrega columna `migrated_from_cache`
-   Agrega columna `migrated_at`

### 4. Dry-Run

-   Prueba la migración sin hacer cambios
-   Muestra qué se va a migrar

### 5. Migración Real

-   Setea `unapplied_amount` en cada pago
-   Marca con `migrated_from_cache = true`
-   Registra `migrated_at` timestamp
-   Limpia cache de Redis

### 6. Verificación

-   Ejecuta `php artisan payments:verify-migration`
-   Genera análisis con `php artisan payments:analyze-migrated`
-   Verifica en base de datos
-   Verifica cache limpio

### 7. Descarga Análisis

-   Descarga CSV con análisis
-   Descarga backup JSON

## 📁 Archivos Generados

### En el servidor (128.199.1.223):

```
~/backup_cache_production_YYYYMMDD_HHMMSS.json
storage/app/backup_cache_production_YYYYMMDD_HHMMSS.json
storage/app/migrated_payments_analysis_YYYYMMDD_HHMMSS.csv
```

### En tu máquina local:

```
~/backups_controlcd_production/backup_cache_production_*.json
~/backups_controlcd_production/migrated_payments_analysis_*.csv
```

## ✅ Verificación Post-Migración

### En Base de Datos:

```sql
-- Ver pagos migrados
SELECT
    COUNT(*) as total,
    SUM(amount) as total_amount,
    SUM(unapplied_amount) as total_unapplied
FROM payments
WHERE migrated_from_cache = 1;

-- Debería retornar:
-- total: 11
-- total_amount: 318.00
-- total_unapplied: 318.00
```

### En Redis:

```bash
redis-cli KEYS "credit:*:pending_payments*"
# Debería retornar: (empty list or set)
```

### Análisis:

```bash
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223
cd /var/www/controlcd-api
php artisan payments:analyze-migrated
```

## 🧪 Prueba Funcional

Después de la migración:

1. **Hacer un pago nuevo** a uno de los créditos migrados (ej: Credit #825)
2. **Verificar FIFO**: Los pagos antiguos deben aplicarse primero
3. **Verificar unapplied_amount**: Debe disminuir correctamente

```sql
-- Ver cómo se aplicaron los pagos
SELECT
    p.id,
    p.amount,
    p.unapplied_amount,
    p.migrated_from_cache,
    pi.installment_id,
    pi.applied_amount
FROM payments p
LEFT JOIN payment_installments pi ON p.id = pi.payment_id
WHERE p.credit_id = 825
ORDER BY p.created_at ASC;
```

## 🔄 Rollback

Si algo sale mal:

```bash
# Conectar al servidor
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@128.199.1.223
cd /var/www/controlcd-api

# Revertir migraciones
php artisan migrate:rollback --step=2

# Esto elimina las columnas:
# - unapplied_amount
# - migrated_from_cache
# - migrated_at
```

## 📝 Checklist Final

Antes de ejecutar en producción:

-   [ ] Backup de base de datos realizado
-   [ ] Ventana de mantenimiento planificada
-   [ ] Usuarios notificados (si aplica)
-   [ ] Probado en staging exitosamente
-   [ ] Datos de cache extraídos y revisados
-   [ ] Plan de rollback listo

Durante la ejecución:

-   [ ] Extracción exitosa
-   [ ] Migraciones DB aplicadas
-   [ ] Dry-run sin errores
-   [ ] Migración real completada
-   [ ] Verificación pasada
-   [ ] Cache Redis limpio

Post-migración:

-   [ ] Análisis revisado
-   [ ] Prueba funcional exitosa
-   [ ] Logs sin errores
-   [ ] Monitoreo activo

## 🚀 Siguiente Paso

Una vez completada la migración exitosamente:

```bash
# Desplegar el código completo con el nuevo sistema
./deploy-to-server-prod.sh
```

## 📞 Soporte

Si encuentras algún problema:

1. Revisa los logs: `tail -f storage/logs/laravel.log`
2. Verifica el análisis generado
3. Consulta el backup JSON
4. Ejecuta rollback si es necesario

---

**Creado**: 2025-12-04
**Servidor**: 128.199.1.223 (Producción)
**Pagos a migrar**: 11 pagos, $318 total
