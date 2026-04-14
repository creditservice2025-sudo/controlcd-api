# Guía de Instalación de PostgreSQL en Servidor cPanel

**Servidor Staging:** 146.190.147.164  
**Sistema Operativo:** AlmaLinux 9.7 (RHEL-based)  
**cPanel:** v11.130.0.16  
**PostgreSQL Version:** 13.23  
**Fecha:** Abril 2026

---

## 📋 ÍNDICE

1. [Análisis de Viabilidad](#análisis-de-viabilidad)
2. [Prerrequisitos](#prerrequisitos)
3. [Plan de Instalación Completo](#plan-de-instalación-completo)
4. [Configuración Post-Instalación](#configuración-post-instalación)
5. [Verificación y Testing](#verificación-y-testing)
6. [Troubleshooting](#troubleshooting)
7. [Mantenimiento](#mantenimiento)

---

## 📊 ANÁLISIS DE VIABILIDAD

### Estado del Servidor (Staging - 146.190.147.164)

#### Recursos Disponibles
```
RAM:   7.8 GB total (4.1 GB disponible, 3.7 GB en uso)
Disco: 99 GB total, 18 GB usado, 82 GB libres (18% uso)
Swap:  No configurado
```

#### Base de Datos Actual
```
MariaDB: v10.11.16 (activo)
Puerto:  3306 (en uso)
Memoria: ~2.0 GB
Estado:  Active (running)
```

#### PostgreSQL a Instalar
```
Versión:    PostgreSQL 13.23
Repositorio: appstream (oficial AlmaLinux)
Puerto:     5432 (LIBRE ✅)
```

### ✅ VIABILIDAD: ALTA - SIN CONFLICTOS

**Razones de compatibilidad:**

1. **Puertos diferentes:**
   - MariaDB: puerto 3306
   - PostgreSQL: puerto 5432 (sin conflicto)

2. **Servicios independientes:**
   - MariaDB: `mariadb.service`
   - PostgreSQL: `postgresql.service`

3. **Directorios separados:**
   - MariaDB: `/var/lib/mysql/`
   - PostgreSQL: `/var/lib/pgsql/`

4. **Recursos suficientes:**
   - RAM disponible: 4.1 GB (PostgreSQL usará ~512MB-1GB)
   - Disco: 82 GB libres (más que suficiente)

5. **Compatibilidad con cPanel:**
   - cPanel maneja MariaDB nativamente
   - PostgreSQL es independiente y no interfiere

---

## 🔧 PRERREQUISITOS

### Verificación Pre-Instalación

```bash
# 1. Conectar al servidor
ssh -i "/ruta/a/tu/ssh_key" root@IP_SERVIDOR

# 2. Verificar sistema operativo
cat /etc/os-release

# 3. Verificar MariaDB activo
systemctl status mariadb
mysql --version

# 4. Verificar puertos en uso
netstat -tuln | grep -E ':(3306|5432)'

# 5. Verificar recursos
free -h
df -h /

# 6. Verificar si PostgreSQL ya está instalado
rpm -qa | grep -i postgres
which psql

# 7. Verificar repositorios disponibles
dnf list available | grep -i postgresql | head -20
dnf info postgresql postgresql-server
```

### Checklist Pre-Instalación

- [ ] Acceso SSH root al servidor
- [ ] MariaDB funcionando correctamente
- [ ] Puerto 5432 disponible
- [ ] Mínimo 2 GB RAM disponible
- [ ] Mínimo 10 GB disco disponible
- [ ] Backup de MariaDB realizado
- [ ] Horario de bajo tráfico confirmado

---

## 🚀 PLAN DE INSTALACIÓN COMPLETO

### FASE 1: Pre-instalación (Backup y Verificación)

**Tiempo estimado:** 5-10 minutos

#### Paso 1.1: Backup de MariaDB

```bash
# Conectar al servidor
ssh -i "/ruta/a/tu/ssh_key" root@IP_SERVIDOR

# Crear directorio de backups si no existe
mkdir -p /root/backups

# Backup completo de todas las bases de datos
mysqldump --all-databases --single-transaction --quick --lock-tables=false \
  > /root/backups/backup_mysql_pre_postgres_$(date +%Y%m%d_%H%M%S).sql

# Verificar tamaño del backup
ls -lh /root/backups/backup_mysql_pre_postgres_*.sql

# Backup de configuración de MariaDB
tar -czf /root/backups/backup_mysql_config_$(date +%Y%m%d_%H%M%S).tar.gz \
  /etc/my.cnf /etc/my.cnf.d/

# Listar backups creados
ls -lh /root/backups/
```

#### Paso 1.2: Verificar Estado Actual

```bash
# Estado de MariaDB
systemctl status mariadb

# Puertos en uso
netstat -tuln | grep -E ':(3306|5432)'

# Recursos del sistema
free -h
df -h /

# Procesos de base de datos
ps aux | grep -E '(mariadb|mysql)' | grep -v grep
```

#### Paso 1.3: Documentar Estado Actual

```bash
# Crear archivo de estado pre-instalación
cat > /root/backups/pre_postgres_status_$(date +%Y%m%d_%H%M%S).txt << 'EOF'
=== ESTADO PRE-INSTALACIÓN POSTGRESQL ===
Fecha: $(date)
Servidor: $(hostname)

=== SERVICIOS ===
$(systemctl status mariadb | head -10)

=== PUERTOS ===
$(netstat -tuln | grep LISTEN)

=== RECURSOS ===
$(free -h)
$(df -h /)

=== VERSIONES ===
MariaDB: $(mysql --version)
Sistema: $(cat /etc/os-release | grep PRETTY_NAME)
EOF
```

---

### FASE 2: Instalación de PostgreSQL 13

**Tiempo estimado:** 5-10 minutos

#### Paso 2.1: Instalar Paquetes PostgreSQL

```bash
# Actualizar cache de repositorios
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

#### Paso 2.2: Inicializar Base de Datos PostgreSQL

```bash
# Inicializar cluster de base de datos
postgresql-setup --initdb

# Verificar que se creó el directorio de datos
ls -la /var/lib/pgsql/data/

# Verificar archivos de configuración creados
ls -la /var/lib/pgsql/data/*.conf
```

**Archivos importantes creados:**
- `/var/lib/pgsql/data/postgresql.conf` - Configuración principal
- `/var/lib/pgsql/data/pg_hba.conf` - Autenticación
- `/var/lib/pgsql/data/pg_ident.conf` - Mapeo de usuarios

#### Paso 2.3: Configurar Timezone America/Bogota

```bash
# Backup de configuración original
cp /var/lib/pgsql/data/postgresql.conf \
   /var/lib/pgsql/data/postgresql.conf.backup

# Configurar timezone
sed -i "s/#timezone = 'GMT'/timezone = 'America\/Bogota'/" \
  /var/lib/pgsql/data/postgresql.conf

sed -i "s/#log_timezone = 'GMT'/log_timezone = 'America\/Bogota'/" \
  /var/lib/pgsql/data/postgresql.conf

# Verificar cambios
grep -E "^timezone|^log_timezone" /var/lib/pgsql/data/postgresql.conf
```

**Configuraciones adicionales recomendadas:**

```bash
# Editar postgresql.conf manualmente si es necesario
nano /var/lib/pgsql/data/postgresql.conf

# Configuraciones importantes:
# listen_addresses = 'localhost'          # Solo local por defecto
# port = 5432                             # Puerto estándar
# max_connections = 100                   # Conexiones máximas
# shared_buffers = 256MB                  # Memoria compartida
# effective_cache_size = 1GB              # Cache estimado
# timezone = 'America/Bogota'             # Zona horaria
# log_timezone = 'America/Bogota'         # Log zona horaria
```

#### Paso 2.4: Configurar Autenticación (pg_hba.conf)

```bash
# Backup de configuración original
cp /var/lib/pgsql/data/pg_hba.conf \
   /var/lib/pgsql/data/pg_hba.conf.backup

# Editar configuración de autenticación
nano /var/lib/pgsql/data/pg_hba.conf
```

**Configuración recomendada para pg_hba.conf:**

```conf
# TYPE  DATABASE        USER            ADDRESS                 METHOD

# "local" is for Unix domain socket connections only
local   all             all                                     peer

# IPv4 local connections:
host    all             all             127.0.0.1/32            scram-sha-256

# IPv6 local connections:
host    all             all             ::1/128                 scram-sha-256

# Allow replication connections from localhost
local   replication     all                                     peer
host    replication     all             127.0.0.1/32            scram-sha-256
host    replication     all             ::1/128                 scram-sha-256

# IMPORTANTE: Para acceso remoto desde aplicaciones (agregar según necesidad)
# host    controlcd_db    controlcd_user  IP_APLICACION/32        scram-sha-256
```

**Métodos de autenticación:**
- `peer` - Usa el usuario del sistema operativo (solo local)
- `scram-sha-256` - Autenticación con contraseña cifrada (recomendado)
- `md5` - Autenticación MD5 (legacy, menos seguro)
- `trust` - Sin contraseña (NO usar en producción)

---

### FASE 3: Configuración de Red y Firewall

**Tiempo estimado:** 2-3 minutos

#### Paso 3.1: Configurar Firewall

```bash
# Verificar estado actual del firewall
firewall-cmd --list-all

# Abrir puerto 5432 para PostgreSQL (permanente)
firewall-cmd --permanent --add-port=5432/tcp

# Recargar firewall
firewall-cmd --reload

# Verificar que el puerto fue agregado
firewall-cmd --list-ports | grep 5432

# Ver configuración completa
firewall-cmd --list-all
```

**Nota:** Por defecto, PostgreSQL solo escucha en localhost. Si necesitas acceso remoto, debes modificar `listen_addresses` en `postgresql.conf`.

#### Paso 3.2: Habilitar e Iniciar Servicio PostgreSQL

```bash
# Habilitar PostgreSQL para inicio automático
systemctl enable postgresql

# Iniciar servicio PostgreSQL
systemctl start postgresql

# Verificar estado del servicio
systemctl status postgresql

# Verificar que está escuchando en el puerto 5432
netstat -tuln | grep 5432

# Verificar logs de inicio
journalctl -u postgresql -n 50 --no-pager
```

#### Paso 3.3: Verificar Ambos Servicios Activos

```bash
# Verificar MariaDB
systemctl status mariadb

# Verificar PostgreSQL
systemctl status postgresql

# Verificar ambos puertos
netstat -tuln | grep -E ':(3306|5432)'

# Debe mostrar:
# tcp  0.0.0.0:3306  (MariaDB)
# tcp  127.0.0.1:5432 (PostgreSQL)
```

---

### FASE 4: Verificación Post-Instalación

**Tiempo estimado:** 3-5 minutos

#### Paso 4.1: Verificar Servicios Simultáneos

```bash
# Estado de ambos servicios
systemctl status mariadb postgresql

# Puertos en uso
netstat -tuln | grep -E ':(3306|5432)'

# Procesos activos
ps aux | grep -E '(mariadb|postgres)' | grep -v grep

# Recursos del sistema
free -h
```

#### Paso 4.2: Probar Conexión PostgreSQL

```bash
# Cambiar a usuario postgres
su - postgres

# Conectar a PostgreSQL
psql

# Dentro de psql, ejecutar:
\conninfo
SELECT version();
SELECT current_database();
SELECT current_user;
SHOW timezone;
SELECT now();

# Listar bases de datos
\l

# Salir de psql
\q

# Volver a root
exit
```

#### Paso 4.3: Verificar Recursos del Sistema

```bash
# Memoria RAM
free -h

# Uso de disco
df -h /var/lib/pgsql/

# Procesos de bases de datos con uso de recursos
ps aux --sort=-%mem | grep -E '(mariadb|postgres)' | grep -v grep | head -10

# Logs de PostgreSQL
tail -50 /var/lib/pgsql/data/log/postgresql-*.log
```

---

### FASE 5: Configuración Inicial de PostgreSQL

**Tiempo estimado:** 5 minutos

#### Paso 5.1: Configurar Contraseña de Usuario postgres

```bash
# Cambiar a usuario postgres
su - postgres

# Conectar a PostgreSQL
psql

# Establecer contraseña para usuario postgres
ALTER USER postgres WITH PASSWORD 'TU_CONTRASEÑA_SEGURA_AQUI';

# Verificar
\du

# Salir
\q
exit
```

#### Paso 5.2: Crear Usuario y Base de Datos de Prueba

```bash
# Como usuario postgres
su - postgres
psql

# Crear usuario de aplicación
CREATE USER controlcd_user WITH PASSWORD 'password_seguro_aqui';

# Crear base de datos
CREATE DATABASE controlcd_test OWNER controlcd_user;

# Otorgar privilegios
GRANT ALL PRIVILEGES ON DATABASE controlcd_test TO controlcd_user;

# Verificar creación
\l
\du

# Conectar a la nueva base de datos
\c controlcd_test

# Crear tabla de prueba
CREATE TABLE test_table (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

# Insertar datos de prueba
INSERT INTO test_table (name) VALUES ('Test 1'), ('Test 2');

# Verificar
SELECT * FROM test_table;

# Verificar timezone
SELECT now();

# Salir
\q
exit
```

#### Paso 5.3: Probar Conexión con Usuario Creado

```bash
# Probar conexión desde línea de comandos
psql -U controlcd_user -d controlcd_test -h localhost

# Dentro de psql:
SELECT * FROM test_table;
\dt
\q
```

---

## 🔍 VERIFICACIÓN Y TESTING

### Checklist de Verificación Post-Instalación

```bash
# Script de verificación completo
cat > /root/verify_postgresql.sh << 'EOF'
#!/bin/bash
echo "=== VERIFICACIÓN POSTGRESQL ==="
echo ""

echo "1. Servicios activos:"
systemctl is-active mariadb
systemctl is-active postgresql
echo ""

echo "2. Puertos en uso:"
netstat -tuln | grep -E ':(3306|5432)'
echo ""

echo "3. Versiones:"
echo "MariaDB: $(mysql --version)"
echo "PostgreSQL: $(psql --version)"
echo ""

echo "4. Recursos:"
free -h | grep Mem
df -h / | tail -1
echo ""

echo "5. Procesos de bases de datos:"
ps aux | grep -E '(mariadb|postgres)' | grep -v grep | wc -l
echo ""

echo "6. Test de conexión PostgreSQL:"
su - postgres -c "psql -c 'SELECT version();'" 2>&1 | head -2
echo ""

echo "7. Bases de datos PostgreSQL:"
su - postgres -c "psql -l" | grep -E '(Name|---|controlcd)'
echo ""

echo "=== VERIFICACIÓN COMPLETA ==="
EOF

chmod +x /root/verify_postgresql.sh
/root/verify_postgresql.sh
```

### Tests de Funcionalidad

```bash
# Test 1: Conexión local
su - postgres -c "psql -c 'SELECT 1;'"

# Test 2: Timezone correcto
su - postgres -c "psql -c 'SHOW timezone;'"
su - postgres -c "psql -c 'SELECT now();'"

# Test 3: Crear y eliminar tabla
su - postgres -c "psql -d controlcd_test -c 'CREATE TABLE test (id INT); DROP TABLE test;'"

# Test 4: Verificar extensiones disponibles
su - postgres -c "psql -c 'SELECT * FROM pg_available_extensions;'"

# Test 5: Verificar configuración
su - postgres -c "psql -c 'SHOW all;'" | grep -E '(timezone|port|max_connections)'
```

---

## 🔧 TROUBLESHOOTING

### Problemas Comunes y Soluciones

#### 1. PostgreSQL no inicia

```bash
# Verificar logs
journalctl -u postgresql -n 100 --no-pager

# Verificar permisos del directorio de datos
ls -la /var/lib/pgsql/
chown -R postgres:postgres /var/lib/pgsql/

# Verificar configuración
su - postgres -c "postgres -D /var/lib/pgsql/data --check"

# Reiniciar servicio
systemctl restart postgresql
```

#### 2. No se puede conectar a PostgreSQL

```bash
# Verificar que el servicio está corriendo
systemctl status postgresql

# Verificar puerto
netstat -tuln | grep 5432

# Verificar pg_hba.conf
cat /var/lib/pgsql/data/pg_hba.conf

# Verificar logs
tail -50 /var/lib/pgsql/data/log/postgresql-*.log
```

#### 3. Error de autenticación

```bash
# Verificar método de autenticación en pg_hba.conf
cat /var/lib/pgsql/data/pg_hba.conf | grep -v "^#" | grep -v "^$"

# Cambiar temporalmente a trust para resetear contraseña
nano /var/lib/pgsql/data/pg_hba.conf
# Cambiar método a 'trust' temporalmente

# Recargar configuración
systemctl reload postgresql

# Resetear contraseña
su - postgres -c "psql -c \"ALTER USER postgres WITH PASSWORD 'nueva_password';\""

# Volver a cambiar método a scram-sha-256
nano /var/lib/pgsql/data/pg_hba.conf

# Recargar
systemctl reload postgresql
```

#### 4. Conflicto de puertos

```bash
# Verificar qué está usando el puerto 5432
netstat -tuln | grep 5432
lsof -i :5432

# Si hay conflicto, cambiar puerto en postgresql.conf
nano /var/lib/pgsql/data/postgresql.conf
# Cambiar: port = 5433 (o el que esté libre)

# Reiniciar
systemctl restart postgresql

# Actualizar firewall
firewall-cmd --permanent --add-port=5433/tcp
firewall-cmd --reload
```

#### 5. Problemas de memoria

```bash
# Verificar uso de memoria
free -h
ps aux --sort=-%mem | head -10

# Ajustar configuración de PostgreSQL
nano /var/lib/pgsql/data/postgresql.conf

# Reducir:
# shared_buffers = 128MB
# effective_cache_size = 512MB
# work_mem = 4MB

# Reiniciar
systemctl restart postgresql
```

#### 6. MariaDB afectado después de instalar PostgreSQL

```bash
# Verificar estado de MariaDB
systemctl status mariadb

# Si no está corriendo, iniciar
systemctl start mariadb

# Verificar logs
journalctl -u mariadb -n 50 --no-pager

# Verificar puerto
netstat -tuln | grep 3306

# Probar conexión
mysql -u root -p -e "SELECT 1;"
```

---

## 🔄 MANTENIMIENTO

### Backups Automáticos de PostgreSQL

```bash
# Crear script de backup
cat > /root/backup_postgresql.sh << 'EOF'
#!/bin/bash

# Configuración
BACKUP_DIR="/root/backups/postgresql"
RETENTION_DAYS=7
DATE=$(date +%Y%m%d_%H%M%S)

# Crear directorio si no existe
mkdir -p $BACKUP_DIR

# Backup de todas las bases de datos
su - postgres -c "pg_dumpall" | gzip > $BACKUP_DIR/postgresql_all_$DATE.sql.gz

# Backup individual de base de datos específica
su - postgres -c "pg_dump controlcd_test" | gzip > $BACKUP_DIR/postgresql_controlcd_test_$DATE.sql.gz

# Eliminar backups antiguos
find $BACKUP_DIR -name "postgresql_*.sql.gz" -mtime +$RETENTION_DAYS -delete

# Log
echo "$(date): Backup completado - $BACKUP_DIR/postgresql_all_$DATE.sql.gz" >> /var/log/postgresql_backup.log
EOF

chmod +x /root/backup_postgresql.sh
```

### Programar Backup Diario con Cron

```bash
# Editar crontab
crontab -e

# Agregar línea para backup diario a las 2 AM
0 2 * * * /root/backup_postgresql.sh

# Verificar crontab
crontab -l
```

### Monitoreo de Recursos

```bash
# Script de monitoreo
cat > /root/monitor_databases.sh << 'EOF'
#!/bin/bash

echo "=== MONITOREO DE BASES DE DATOS ==="
echo "Fecha: $(date)"
echo ""

echo "=== SERVICIOS ==="
systemctl status mariadb postgresql | grep "Active:"
echo ""

echo "=== MEMORIA ==="
free -h | grep -E '(Mem|Swap)'
echo ""

echo "=== PROCESOS ==="
ps aux --sort=-%mem | grep -E '(mariadb|postgres)' | grep -v grep | head -5
echo ""

echo "=== CONEXIONES POSTGRESQL ==="
su - postgres -c "psql -c 'SELECT count(*) as connections FROM pg_stat_activity;'"
echo ""

echo "=== TAMAÑO DE BASES DE DATOS ==="
su - postgres -c "psql -c 'SELECT pg_database.datname, pg_size_pretty(pg_database_size(pg_database.datname)) AS size FROM pg_database ORDER BY pg_database_size(pg_database.datname) DESC;'"
echo ""
EOF

chmod +x /root/monitor_databases.sh
```

### Actualización de PostgreSQL

```bash
# Verificar actualizaciones disponibles
dnf check-update postgresql postgresql-server

# Actualizar (con precaución)
# IMPORTANTE: Hacer backup antes de actualizar
/root/backup_postgresql.sh

# Actualizar paquetes
dnf update postgresql postgresql-server

# Reiniciar servicio
systemctl restart postgresql

# Verificar versión
psql --version
su - postgres -c "psql -c 'SELECT version();'"
```

### Limpieza y Optimización

```bash
# Vacuum y análisis de bases de datos
su - postgres -c "psql -d controlcd_test -c 'VACUUM ANALYZE;'"

# Reindexar base de datos
su - postgres -c "psql -d controlcd_test -c 'REINDEX DATABASE controlcd_test;'"

# Limpiar logs antiguos (más de 30 días)
find /var/lib/pgsql/data/log/ -name "postgresql-*.log" -mtime +30 -delete
```

---

## 📝 NOTAS IMPORTANTES PARA PRODUCCIÓN

### Diferencias entre Staging y Producción

1. **Contraseñas:**
   - Usar contraseñas diferentes y más seguras en producción
   - Almacenar credenciales en gestor de secretos

2. **Configuración de Memoria:**
   - Ajustar `shared_buffers` y `effective_cache_size` según RAM disponible
   - En producción con más RAM, aumentar estos valores

3. **Conexiones:**
   - Ajustar `max_connections` según carga esperada
   - Considerar usar connection pooler (PgBouncer) en producción

4. **Backups:**
   - Implementar backups automáticos diarios
   - Guardar backups en ubicación externa/remota
   - Probar restauración de backups regularmente

5. **Monitoreo:**
   - Implementar monitoreo de recursos (CPU, RAM, Disco)
   - Configurar alertas para problemas críticos
   - Usar herramientas como pg_stat_statements

6. **Seguridad:**
   - Configurar pg_hba.conf restrictivamente
   - Usar solo scram-sha-256 para autenticación
   - Mantener PostgreSQL actualizado
   - Configurar SSL/TLS para conexiones remotas

7. **Firewall:**
   - Restringir acceso al puerto 5432 solo a IPs necesarias
   - No exponer PostgreSQL públicamente si no es necesario

### Checklist Pre-Producción

- [ ] Backups automáticos configurados y probados
- [ ] Contraseñas seguras establecidas
- [ ] pg_hba.conf configurado restrictivamente
- [ ] Firewall configurado correctamente
- [ ] Monitoreo implementado
- [ ] Documentación actualizada con credenciales
- [ ] Plan de rollback preparado
- [ ] Pruebas de carga realizadas
- [ ] Equipo notificado del cambio

---

## 📞 CONTACTO Y SOPORTE

### Logs Importantes

```bash
# Logs de PostgreSQL
/var/lib/pgsql/data/log/postgresql-*.log

# Logs del sistema
journalctl -u postgresql -n 100

# Logs de MariaDB (para comparación)
/var/log/mariadb/mariadb.log
journalctl -u mariadb -n 100
```

### Comandos Útiles de Referencia Rápida

```bash
# PostgreSQL
systemctl status postgresql
systemctl start postgresql
systemctl stop postgresql
systemctl restart postgresql
su - postgres -c "psql"

# MariaDB
systemctl status mariadb
mysql -u root -p

# Verificación rápida
netstat -tuln | grep -E ':(3306|5432)'
ps aux | grep -E '(mariadb|postgres)' | grep -v grep
free -h
df -h /var/lib/pgsql/
```

---

## 📚 RECURSOS ADICIONALES

- [Documentación Oficial PostgreSQL 13](https://www.postgresql.org/docs/13/)
- [PostgreSQL Wiki](https://wiki.postgresql.org/)
- [AlmaLinux Documentation](https://wiki.almalinux.org/)
- [cPanel Documentation](https://docs.cpanel.net/)

---

**Última actualización:** Abril 2026  
**Versión del documento:** 1.0  
**Autor:** Sistema ControCD  
**Servidor de referencia:** 146.190.147.164 (Staging)
