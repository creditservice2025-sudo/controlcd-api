# Extracción de Cache - Producción

## ⚠️ IMPORTANTE - SOLO EXTRACCIÓN

Este script **SOLO extrae** los datos del cache de Redis en producción.
**NO hace la migración completa**.

## Uso

```bash
cd /home/mario-d-az/git/ControCD-Backend
./extract-cache-production.sh
```

## Lo que hace

1. ✅ Sube el comando `ExtractCachedPayments.php` al servidor
2. ✅ Ejecuta `php artisan payments:extract-cached`
3. ✅ Crea backup JSON en el servidor
4. ✅ Descarga el backup a tu máquina local

## Archivos Generados

### En el servidor (128.199.1.223):

-   `~/backup_cache_production_YYYYMMDD_HHMMSS.json`
-   `storage/app/backup_cache_production_YYYYMMDD_HHMMSS.json`

### En tu máquina local:

-   `~/backups_controlcd_production/backup_cache_production_YYYYMMDD_HHMMSS.json`

## Analizar el JSON

```bash
# Ver contenido formateado
cat ~/backups_controlcd_production/backup_cache_production_*.json | jq .

# Contar créditos con cache
cat ~/backups_controlcd_production/backup_cache_production_*.json | jq 'length'

# Ver total de pagos
cat ~/backups_controlcd_production/backup_cache_production_*.json | jq '[.[].payment_count] | add'

# Ver total acumulado
cat ~/backups_controlcd_production/backup_cache_production_*.json | jq '[.[].accumulated_amount] | add'
```

## Ejemplo de Salida

```json
[
    {
        "credit_id": 123,
        "client_name": "Juan Pérez",
        "client_dni": "12345678",
        "accumulated_amount": 150.0,
        "pending_payments": [
            {
                "payment_id": 456,
                "amount": 50.0,
                "payment_date": "2025-12-01"
            },
            {
                "payment_id": 457,
                "amount": 100.0,
                "payment_date": "2025-12-02"
            }
        ],
        "payment_count": 2,
        "extracted_at": "2025-12-04 17:00:00"
    }
]
```

## Próximos Pasos

Si el JSON muestra pagos en cache:

1. **Revisar los datos** extraídos
2. **Planificar ventana de mantenimiento** para producción
3. **Ejecutar migración completa** con el script correspondiente

## Notas

-   ⚠️ Este script es SEGURO - solo lee datos, no modifica nada
-   ✅ Puedes ejecutarlo múltiples veces
-   ✅ No afecta el funcionamiento del sistema
-   ✅ No requiere modo mantenimiento
