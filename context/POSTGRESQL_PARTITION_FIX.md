# 🔧 Fix: Error SQLSTATE[25P02] en Particiones PostgreSQL

**Fecha:** Abril 2026  
**Aplicado en:** Staging (146.190.147.164)  
**Pendiente en:** Producción (128.199.1.223)

---

## 🐛 Problema

Al crear un cliente o crédito en Collection, PostgreSQL respondía:

```
SQLSTATE[25P02]: In failed sql transaction: 7 ERROR: current transaction is aborted,
commands ignored until end of transaction block
```

### Causa Raíz

`CollectionPartitionService` intentaba crear particiones para **8 tablas**, pero solo **4 son particionadas**. Al ejecutar `CREATE TABLE ... PARTITION OF` sobre una tabla regular, PostgreSQL lanza `"X is not partitioned"`, lo cual **envenena la conexión** — todos los SQL posteriores fallan con `25P02`.

### Tablas Particionadas vs Regulares

| Tabla                      | Tipo        | Necesita Partición |
| -------------------------- | ----------- | ------------------ |
| `collection_clients`       | partitioned | ✅ Sí              |
| `collection_credits`       | partitioned | ✅ Sí              |
| `collection_installments`  | partitioned | ✅ Sí              |
| `collection_payments`      | partitioned | ✅ Sí              |
| `collection_client_audits` | regular     | ❌ No              |
| `collection_expenses`      | regular     | ❌ No              |
| `collection_wallets`       | regular     | ❌ No              |
| `collection_ledger`        | regular     | ❌ No              |

---

## ✅ Corrección Aplicada

### 1. Código: `CollectionPartitionService.php`

Se eliminaron las 4 tablas regulares de `PARTITIONED_TABLES`:

```diff
 private const PARTITIONED_TABLES = [
     'collection_clients',
-    'collection_client_audits',
     'collection_credits',
     'collection_installments',
     'collection_payments',
-    'collection_expenses',
-    'collection_wallets',
-    'collection_ledger',
 ];
```

### 2. Base de Datos: Crear particiones por company_id

Para cada `company_id` activo, ejecutar en PostgreSQL:

```sql
-- Reemplazar {ID} con el company_id real (ej: 1, 2, etc.)
CREATE TABLE IF NOT EXISTS collection_clients_company_{ID}
  PARTITION OF collection_clients FOR VALUES IN ({ID});

CREATE TABLE IF NOT EXISTS collection_credits_company_{ID}
  PARTITION OF collection_credits FOR VALUES IN ({ID});

CREATE TABLE IF NOT EXISTS collection_installments_company_{ID}
  PARTITION OF collection_installments FOR VALUES IN ({ID});

CREATE TABLE IF NOT EXISTS collection_payments_company_{ID}
  PARTITION OF collection_payments FOR VALUES IN ({ID});
```

---

## 🚀 Pasos para Producción

### Prerequisitos

```bash
# Conectar al servidor de producción
ssh -i ~/.ssh/id_rsa_mario_controlcd root@128.199.1.223
```

### Paso 1: Permisos del usuario PostgreSQL

```bash
# Otorgar permisos para crear tablas en el schema public
su - postgres -c "psql -d 'controlcd-cobranza' -c 'GRANT ALL ON SCHEMA public TO controlcd_user;'"
su - postgres -c "psql -d 'controlcd-cobranza' -c 'ALTER SCHEMA public OWNER TO controlcd_user;'"
```

### Paso 2: Verificar tablas particionadas

```bash
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "
  SELECT relname, CASE relkind WHEN 'p' THEN 'partitioned' WHEN 'r' THEN 'regular' END as type
  FROM pg_class
  WHERE relname IN (
    'collection_clients','collection_client_audits','collection_credits',
    'collection_installments','collection_payments','collection_expenses',
    'collection_wallets','collection_ledger'
  );
"
```

### Paso 3: Crear particiones para cada company activa

```bash
# Obtener IDs de companies activas desde MySQL
mysql -u root -p controlcd-prod -e "SELECT id FROM companies WHERE deleted_at IS NULL;"

# Para cada company_id, ejecutar:
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "
  CREATE TABLE IF NOT EXISTS collection_clients_company_{ID} PARTITION OF collection_clients FOR VALUES IN ({ID});
  CREATE TABLE IF NOT EXISTS collection_credits_company_{ID} PARTITION OF collection_credits FOR VALUES IN ({ID});
  CREATE TABLE IF NOT EXISTS collection_installments_company_{ID} PARTITION OF collection_installments FOR VALUES IN ({ID});
  CREATE TABLE IF NOT EXISTS collection_payments_company_{ID} PARTITION OF collection_payments FOR VALUES IN ({ID});
"
```

### Paso 4: Verificar

```bash
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "
  CREATE TABLE test_perm (id serial PRIMARY KEY);
  DROP TABLE test_perm;
  SELECT 'PERMISSIONS OK' as result;
"
```

### Paso 5: Desplegar código

Asegurar que `CollectionPartitionService.php` con el fix esté desplegado.

---

## 📝 Notas

- El cambio de código es **seguro**: solo elimina un paso innecesario que causaba el error.
- Las tablas regulares (`collection_client_audits`, etc.) funcionan normalmente sin particiones.
- Las particiones se crean automáticamente por `ensurePartitions()` para companies nuevas, pero la primera vez deben existir.
