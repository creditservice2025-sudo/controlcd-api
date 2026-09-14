# 🐘 Configuración PostgreSQL en Producción - ControCD

Guía completa para configurar PostgreSQL en el servidor de producción, basada en la implementación exitosa en staging.

**Servidor Staging (Referencia):** 146.190.147.164  
**Servidor Producción:** 128.199.1.223  
**Fecha:** Abril 2026

---

## 📋 ÍNDICE

1. [Requisitos Previos](#requisitos-previos)
2. [Instalación de PostgreSQL](#instalación-de-postgresql)
3. [Configuración de Acceso Remoto](#configuración-de-acceso-remoto)
4. [Instalación de Driver PHP PostgreSQL](#instalación-de-driver-php-postgresql)
5. [Configuración de Laravel](#configuración-de-laravel)
6. [Ejecución de Migraciones](#ejecución-de-migraciones)
7. [Verificación y Testing](#verificación-y-testing)
8. [Troubleshooting](#troubleshooting)

---

## 🔧 REQUISITOS PREVIOS

### Checklist Pre-Instalación

- [ ] Acceso SSH root al servidor de producción
- [ ] Backup completo de MySQL/MariaDB
- [ ] Verificar recursos disponibles (RAM, Disco)
- [ ] Horario de bajo tráfico confirmado
- [ ] Credenciales de PostgreSQL definidas

### Verificar Estado del Servidor

```bash
# Conectar al servidor
ssh -i ~/.ssh/id_rsa_mario_controlcd root@128.199.1.223

# Verificar recursos
free -h
df -h /

# Verificar puertos disponibles
netstat -tuln | grep -E ':(3306|5432)'

# Verificar si PostgreSQL ya está instalado
rpm -qa | grep -i postgres
which psql
```

---

## 📦 INSTALACIÓN DE POSTGRESQL

### Paso 1: Instalar PostgreSQL 13

```bash
# Actualizar repositorios
dnf clean all
dnf makecache

# Instalar PostgreSQL y componentes
dnf install -y postgresql postgresql-server postgresql-contrib

# Verificar instalación
rpm -qa | grep postgresql
psql --version
```

**Paquetes instalados:**
- `postgresql` - Cliente PostgreSQL
- `postgresql-server` - Servidor PostgreSQL
- `postgresql-contrib` - Módulos adicionales y extensiones

### Paso 2: Inicializar Base de Datos

```bash
# Inicializar cluster de base de datos
postgresql-setup --initdb

# Verificar creación de directorios
ls -la /var/lib/pgsql/data/
ls -la /var/lib/pgsql/data/*.conf
```

### Paso 3: Configurar Timezone

```bash
# Backup de configuración original
cp /var/lib/pgsql/data/postgresql.conf /var/lib/pgsql/data/postgresql.conf.backup

# Configurar timezone America/Bogota
sed -i "s/#timezone = 'GMT'/timezone = 'America\/Bogota'/" /var/lib/pgsql/data/postgresql.conf
sed -i "s/#log_timezone = 'GMT'/log_timezone = 'America\/Bogota'/" /var/lib/pgsql/data/postgresql.conf

# Verificar cambios
grep -E "^timezone|^log_timezone" /var/lib/pgsql/data/postgresql.conf
```

### Paso 4: Iniciar y Habilitar Servicio

```bash
# Habilitar PostgreSQL para inicio automático
systemctl enable postgresql

# Iniciar servicio PostgreSQL
systemctl start postgresql

# Verificar estado
systemctl status postgresql

# Verificar puerto
netstat -tuln | grep 5432
```

---

## 🌐 CONFIGURACIÓN DE ACCESO REMOTO

### Paso 1: Configurar postgresql.conf

```bash
# Backup de configuración
cp /var/lib/pgsql/data/postgresql.conf /var/lib/pgsql/data/postgresql.conf.backup2

# Permitir conexiones desde todas las interfaces
sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/" /var/lib/pgsql/data/postgresql.conf
sed -i "s/listen_addresses = 'localhost'/listen_addresses = '*'/" /var/lib/pgsql/data/postgresql.conf

# Verificar
grep "^listen_addresses" /var/lib/pgsql/data/postgresql.conf
```

### Paso 2: Configurar pg_hba.conf

```bash
# Backup de configuración
cp /var/lib/pgsql/data/pg_hba.conf /var/lib/pgsql/data/pg_hba.conf.backup

# Editar archivo
nano /var/lib/pgsql/data/pg_hba.conf
```

**Agregar al final del archivo:**

```conf
# TYPE  DATABASE        USER            ADDRESS                 METHOD

# "local" is for Unix domain socket connections only
local   all             all                                     peer

# IPv4 local connections - USANDO MD5 PARA PASSWORDS
host    all             all             127.0.0.1/32            md5

# IPv6 local connections
host    all             all             ::1/128                 md5

# Allow replication connections from localhost
local   replication     all                                     peer
host    replication     all             127.0.0.1/32            md5
host    replication     all             ::1/128                 md5

# Remote access for ControCD - USANDO MD5
# IMPORTANTE: En producción, restringe a IPs específicas
host    all             all             0.0.0.0/0               md5
```

**⚠️ SEGURIDAD EN PRODUCCIÓN:**

Para mayor seguridad, reemplaza `0.0.0.0/0` con IPs específicas:

```conf
# Solo permitir desde servidor de aplicación
host    all             all             IP_SERVIDOR_APP/32      md5

# Solo permitir desde tu IP de desarrollo
host    all             all             TU_IP_PUBLICA/32        md5
```

### Paso 3: Configurar Firewall

```bash
# Abrir puerto 5432
firewall-cmd --permanent --add-port=5432/tcp
firewall-cmd --reload

# Verificar
firewall-cmd --list-ports | grep 5432
```

### Paso 4: Reiniciar PostgreSQL

```bash
# Recargar configuración
systemctl reload postgresql

# O reiniciar completamente
systemctl restart postgresql

# Verificar que escucha en todas las interfaces
netstat -tuln | grep 5432
# Debe mostrar: 0.0.0.0:5432
```

---

## 🔌 INSTALACIÓN DE DRIVER PHP POSTGRESQL

### Paso 1: Verificar Versión de PHP

```bash
# Verificar PHP activo
php -v

# En cPanel, verificar PHP 8.3
/opt/cpanel/ea-php83/root/usr/bin/php -v
```

### Paso 2: Instalar Módulo PostgreSQL para PHP

```bash
# Instalar paquete ea-php83-php-pgsql
dnf install -y ea-php83-php-pgsql

# Verificar instalación
/opt/cpanel/ea-php83/root/usr/bin/php -m | grep -i pgsql
```

**Debe mostrar:**
```
pdo_pgsql
pgsql
```

### Paso 3: Reiniciar PHP-FPM

```bash
# Reiniciar PHP-FPM (se hace automáticamente con la instalación)
systemctl restart ea-php83-php-fpm

# Verificar estado
systemctl status ea-php83-php-fpm
```

---

## ⚙️ CONFIGURACIÓN DE LARAVEL

### Paso 1: Crear Usuario y Base de Datos PostgreSQL

```bash
# Cambiar a usuario postgres
su - postgres

# Conectar a PostgreSQL
psql

# Crear usuario de aplicación
CREATE USER controlcd_user WITH PASSWORD 'TU_PASSWORD_SEGURO_AQUI';

# Crear base de datos
CREATE DATABASE "controlcd-cobranza" OWNER controlcd_user;

# Otorgar privilegios
GRANT ALL PRIVILEGES ON DATABASE "controlcd-cobranza" TO controlcd_user;

# Otorgar privilegio CREATEDB (para migraciones)
ALTER USER controlcd_user CREATEDB;

# Otorgar permisos sobre tablespaces
GRANT CREATE ON TABLESPACE pg_default TO controlcd_user;
GRANT CREATE ON TABLESPACE pg_global TO controlcd_user;

# Verificar
\du controlcd_user
\l

# Salir
\q
exit
```

### Paso 2: Habilitar Extensión pg_trgm

```bash
# Conectar a la base de datos
PGPASSWORD='TU_PASSWORD_SEGURO_AQUI' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza

# Habilitar extensión
CREATE EXTENSION IF NOT EXISTS pg_trgm;

# Verificar
\dx

# Salir
\q
```

### Paso 3: Configurar .env en Laravel

```bash
# Editar archivo .env en producción
nano /var/www/controlcd-api/.env
```

**Agregar al final del archivo:**

```env
# CONNECTION A POSTGRES
COLLECTION_DB_HOST=127.0.0.1
COLLECTION_DB_PORT=5432
COLLECTION_DB_DATABASE=controlcd-cobranza
COLLECTION_DB_USERNAME=controlcd_user
COLLECTION_DB_PASSWORD=TU_PASSWORD_SEGURO_AQUI
COLLECTION_DB_SCHEMA=public
COLLECTION_DB_SSLMODE=prefer
```

### Paso 4: Verificar config/database.php

El archivo `config/database.php` debe tener la conexión `collection_pgsql`:

```php
'collection_pgsql' => [
    'driver' => 'pgsql',
    'url' => env('COLLECTION_DB_URL'),
    'host' => env('COLLECTION_DB_HOST', '127.0.0.1'),
    'port' => env('COLLECTION_DB_PORT', '5432'),
    'database' => env('COLLECTION_DB_DATABASE', 'laravel'),
    'username' => env('COLLECTION_DB_USERNAME', 'postgres'),
    'password' => env('COLLECTION_DB_PASSWORD', ''),
    'charset' => env('COLLECTION_DB_CHARSET', 'utf8'),
    'prefix' => env('COLLECTION_DB_PREFIX', ''),
    'prefix_indexes' => true,
    'search_path' => env('COLLECTION_DB_SCHEMA', 'public'),
    'sslmode' => env('COLLECTION_DB_SSLMODE', 'prefer'),
],
```

---

## 🚀 EJECUCIÓN DE MIGRACIONES

### Comando Correcto para Producción

```bash
# Conectar al servidor
ssh -i ~/.ssh/id_rsa_mario_controlcd root@128.199.1.223

# Ir al directorio de la aplicación
cd /var/www/controlcd-api

# Ejecutar migraciones de PostgreSQL (módulo cobranza)
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --database=collection_pgsql --path=database/migrations/collection
```

### Comandos Útiles

```bash
# Ver estado de migraciones PostgreSQL
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate:status --database=collection_pgsql --path=database/migrations/collection

# Rollback última migración
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate:rollback --database=collection_pgsql --path=database/migrations/collection

# Ejecutar migraciones MySQL (por defecto)
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate

# Limpiar caché
/opt/cpanel/ea-php83/root/usr/bin/php artisan cache:clear
/opt/cpanel/ea-php83/root/usr/bin/php artisan config:clear
```

---

## ✅ VERIFICACIÓN Y TESTING

### Verificar Conexión PostgreSQL

```bash
# Desde el servidor
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "SELECT version();"

# Listar tablas creadas
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "\dt"

# Ver extensiones
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "\dx"
```

### Verificar desde Laravel

```bash
# Probar conexión desde tinker
/opt/cpanel/ea-php83/root/usr/bin/php artisan tinker

# Dentro de tinker:
DB::connection('collection_pgsql')->getPdo();
DB::connection('collection_pgsql')->select('SELECT version()');
exit
```

### Script de Verificación Completa

```bash
cat > /root/verify_postgresql_production.sh << 'EOF'
#!/bin/bash

echo "=== VERIFICACIÓN POSTGRESQL PRODUCCIÓN ==="
echo ""

echo "1. Servicio PostgreSQL:"
systemctl status postgresql | grep "Active:"
echo ""

echo "2. Puerto escuchando:"
netstat -tuln | grep 5432
echo ""

echo "3. Módulos PHP:"
/opt/cpanel/ea-php83/root/usr/bin/php -m | grep -i pgsql
echo ""

echo "4. Bases de datos:"
su - postgres -c "psql -l" | grep -E "(Name|controlcd)"
echo ""

echo "5. Extensiones en controlcd-cobranza:"
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "\dx"
echo ""

echo "6. Tablas creadas:"
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "\dt"
echo ""

echo "=== VERIFICACIÓN COMPLETA ==="
EOF

chmod +x /root/verify_postgresql_production.sh
/root/verify_postgresql_production.sh
```

---

## 🔧 TROUBLESHOOTING

### Error: "could not find driver"

**Problema:** PHP no tiene el driver PostgreSQL instalado.

**Solución:**
```bash
dnf install -y ea-php83-php-pgsql
systemctl restart ea-php83-php-fpm
/opt/cpanel/ea-php83/root/usr/bin/php -m | grep pgsql
```

### Error: "operator class gin_trgm_ops does not exist"

**Problema:** Falta la extensión `pg_trgm`.

**Solución:**
```bash
PGPASSWORD='TU_PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
```

### Error: "permission denied to create database"

**Problema:** Usuario no tiene privilegio CREATEDB.

**Solución:**
```bash
su - postgres -c "psql -c 'ALTER USER controlcd_user CREATEDB;'"
```

### Error: "permission denied for tablespace pg_default"

**Problema:** Usuario no tiene permisos sobre tablespaces.

**Solución:**
```bash
su - postgres -c "psql -c 'GRANT CREATE ON TABLESPACE pg_default TO controlcd_user;'"
su - postgres -c "psql -c 'GRANT CREATE ON TABLESPACE pg_global TO controlcd_user;'"
```

### Error: "Connection refused"

**Problema:** PostgreSQL no acepta conexiones remotas.

**Solución:**
1. Verificar `listen_addresses = '*'` en `postgresql.conf`
2. Verificar reglas en `pg_hba.conf`
3. Verificar firewall: `firewall-cmd --list-ports | grep 5432`
4. Reiniciar PostgreSQL: `systemctl restart postgresql`

### Error: "password authentication failed"

**Problema:** Método de autenticación incorrecto o password incorrecta.

**Solución:**
1. Verificar que `pg_hba.conf` use `md5` (no `ident` ni `peer` para conexiones TCP)
2. Resetear password:
```bash
su - postgres -c "psql -c \"ALTER USER controlcd_user WITH PASSWORD 'NUEVA_PASSWORD';\""
```
3. Recargar configuración: `systemctl reload postgresql`

---

## 📊 RESUMEN DE CREDENCIALES

### PostgreSQL Producción

```
Host:     127.0.0.1 (localhost desde servidor)
Host:     128.199.1.223 (remoto)
Puerto:   5432
Database: controlcd-cobranza
Usuario:  controlcd_user
Password: [DEFINIR PASSWORD SEGURO]
Schema:   public
```

### Configuración Laravel (.env)

```env
COLLECTION_DB_HOST=127.0.0.1
COLLECTION_DB_PORT=5432
COLLECTION_DB_DATABASE=controlcd-cobranza
COLLECTION_DB_USERNAME=controlcd_user
COLLECTION_DB_PASSWORD=[PASSWORD_SEGURO]
COLLECTION_DB_SCHEMA=public
COLLECTION_DB_SSLMODE=prefer
```

---

## 📝 CHECKLIST POST-INSTALACIÓN

- [ ] PostgreSQL instalado y corriendo
- [ ] Puerto 5432 abierto en firewall
- [ ] Acceso remoto configurado (si es necesario)
- [ ] Driver PHP PostgreSQL instalado
- [ ] Usuario `controlcd_user` creado con permisos
- [ ] Base de datos `controlcd-cobranza` creada
- [ ] Extensión `pg_trgm` habilitada
- [ ] Archivo `.env` configurado
- [ ] Migraciones ejecutadas exitosamente
- [ ] Conexión verificada desde Laravel
- [ ] Backup automático configurado
- [ ] Documentación actualizada con credenciales

---

## 🔒 SEGURIDAD EN PRODUCCIÓN

### Recomendaciones

1. **Passwords Fuertes:**
   - Usar passwords de al menos 20 caracteres
   - Incluir mayúsculas, minúsculas, números y símbolos
   - Almacenar en gestor de secretos

2. **Acceso Restringido:**
   - Limitar IPs en `pg_hba.conf`
   - No usar `0.0.0.0/0` en producción
   - Usar VPN si es posible

3. **Firewall:**
   - Solo abrir puerto 5432 si es absolutamente necesario
   - Preferir conexiones locales (127.0.0.1)

4. **Backups:**
   - Configurar backups automáticos diarios
   - Probar restauración regularmente
   - Guardar backups en ubicación externa

5. **Monitoreo:**
   - Configurar alertas de recursos
   - Monitorear logs de PostgreSQL
   - Revisar conexiones activas regularmente

---

## 📚 COMANDOS DE REFERENCIA RÁPIDA

```bash
# Conectar al servidor
ssh -i ~/.ssh/id_rsa_mario_controlcd root@128.199.1.223

# Servicios
systemctl status postgresql
systemctl restart postgresql
systemctl reload postgresql

# Migraciones
cd /var/www/controlcd-api
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --database=collection_pgsql --path=database/migrations/collection

# PostgreSQL
su - postgres -c "psql"
PGPASSWORD='PASSWORD' psql -h 127.0.0.1 -U controlcd_user -d controlcd-cobranza

# Verificación
netstat -tuln | grep 5432
/opt/cpanel/ea-php83/root/usr/bin/php -m | grep pgsql
```

---

**Última actualización:** Abril 2026  
**Versión:** 1.0  
**Basado en:** Implementación exitosa en Staging (146.190.147.164)  
**Servidor Producción:** 128.199.1.223
