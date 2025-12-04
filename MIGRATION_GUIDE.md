# Migración de Pagos en Cache - Guía Rápida

## ¿Qué hace este script?

El script `migrate-payment-cache.sh` automatiza la migración de abonos que están en Redis cache al nuevo sistema con `unapplied_amount`.

## Uso

```bash
# Desde el directorio del backend
cd /home/mario-d-az/git/ControCD-Backend

# Ejecutar el script
./migrate-payment-cache.sh
```

## Proceso Automatizado

El script ejecuta estos pasos en orden:

1. ✅ **Sube comandos de migración** al servidor
2. ✅ **Extrae datos del cache** y crea backup JSON
3. ✅ **Aplica migraciones** de base de datos (agrega columnas)
4. ✅ **Ejecuta dry-run** para verificar
5. ✅ **Migra pagos** del cache a `unapplied_amount`
6. ✅ **Verifica** que todo se migró correctamente
7. ✅ **Genera análisis** con estadísticas

## Después de la Migración

Una vez completada la migración exitosamente:

```bash
# Desplegar el código completo
./deploy-to-server.sh
```

## Descargar Análisis

```bash
# Descargar el CSV con el análisis
scp -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd \
  root@146.190.147.164:/var/www/controlcd-api/storage/app/migrated_payments_analysis_*.csv \
  .
```

## Verificación Manual

Si quieres verificar manualmente en el servidor:

```bash
# Conectar al servidor
ssh -i /home/mario-d-az/.ssh/id_rsa_mario_controlcd root@146.190.147.164

# Ir al directorio
cd /var/www/controlcd-api

# Ver pagos migrados
/opt/cpanel/ea-php83/root/usr/bin/php artisan payments:analyze-migrated

# Verificar en base de datos
mysql -u staging -p staging_controlcd
SELECT COUNT(*), SUM(amount), SUM(unapplied_amount)
FROM payments WHERE migrated_from_cache = 1;
```

## Rollback

Si algo sale mal:

```bash
# En el servidor
cd /var/www/controlcd-api

# Revertir migraciones
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate:rollback --step=2

# Restaurar desde backup
# El backup está en: ~/backup_cache_staging_*.json
```

## Notas Importantes

-   ⚠️ **Ejecutar ANTES** de `deploy-to-server.sh`
-   ⚠️ El script crea backups automáticamente
-   ⚠️ Pide confirmación antes de la migración real
-   ✅ Usa la misma configuración SSH que `deploy-to-server.sh`
