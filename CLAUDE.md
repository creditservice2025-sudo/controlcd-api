# Control CD — MARAND CONSULTORES

Sistema de **gestión de créditos y cobranza diaria** ("control cuota diaria") para empresas
prestamistas con cobradores en calle. Cubre el ciclo completo: clientes, créditos, cuotas,
pagos, gastos, ingresos, liquidaciones de cobradores, reportes y notificaciones.

## Arquitectura general

Monorepo lógico de dos proyectos hermanos en `controlCD-MARANDCONSULTORES/`:

- [controlcd-api/](../controlcd-api/) — Backend REST API (Laravel 11 / PHP 8.2)
- [controlcd-app/](../controlcd-app/) — Frontend SPA + APK Android (Quasar/Vue 3)

Comunicación: el frontend consume la API por HTTP/JSON con autenticación por token
(Passport + Sanctum). El APK móvil (Capacitor) usa la misma API que la SPA web.

## Backend — controlcd-api

**Stack:** Laravel 11, PHP 8.2, MySQL, Passport (OAuth), Sanctum, Spatie Permission
(roles/permisos), DomPDF + Laravel Excel (exportes), Resend (correo), Telegram (logs/alertas).

**Estructura clave:**
- [app/Models/](app/Models/) — Dominio: `Client`, `Credit`, `Installment`, `Payment`,
  `Expense`, `Income`, `Liquidation`, `Seller`, `Company`, `User`, `Notification`, etc.
- [app/Http/Controllers/](app/Http/Controllers/) — Controllers REST por recurso.
  Subcarpetas `Api/` y `Collection/` agrupan endpoints específicos.
- [app/Services/](app/Services/) — Lógica de negocio (ej. `LiquidationService` para
  cálculos automáticos de liquidaciones, faltantes/sobrantes y notificaciones retroactivas).
- [routes/](routes/), [config/](config/), [database/migrations/](database/migrations/).

**Roles:** Administrador, Cobrador (Seller), y otros gestionados vía Spatie Permission.
Un Administrador puede tener Sellers asociados; muchos endpoints filtran por esa relación.

### Conceptos imprescindibles del dominio

- **Liquidación:** corte diario por cobrador. Suma ingresos − gastos del día y compara con
  lo entregado, generando faltante/sobrante. La lógica vive en `LiquidationService` y
  dispara notificaciones retroactivas si se detectan inconsistencias.
- **Business date / timezone:** los registros (incomes, expenses, payments) llevan
  campos de "fecha de negocio" calculados según la zona horaria del cliente/empresa
  (Venezuela). Existen scripts de backfill (`backfill_business_dates.php`,
  `fix_venezuela_tz.php`) — **nunca asumir UTC** al calcular cortes diarios.
- **Precisión decimal:** los créditos manejan montos con redondeos sensibles. Ver
  `FixCreditPrecision.php` y `fix_rounding_installments.php`. Cualquier cambio en
  cálculos de cuotas debe preservar la precisión existente para no corromper históricos.
- **Auditoría:** `LiquidationAudit`, `SessionLog`, `TelegramLog` y `ClientHistory`
  conservan trazabilidad. No borrar registros históricos: marcar/soft-delete.

### Convenciones y advertencias

- La raíz del repo contiene **muchos scripts sueltos** (`debug_*.php`, `fix_*.php`,
  `check_*.php`, `verify_*.php`, `recalculate_*.php`) usados puntualmente para diagnóstico
  y reparación de datos en producción/staging. **No son parte del runtime de la API**;
  no editarlos al refactorizar el código de `app/`.
- Despliegue mediante scripts `deploy-*.sh` y `db-tools-*.sh` para staging y producción.
- Migraciones críticas: ver `MIGRATION_GUIDE.md` y `PRODUCTION_MIGRATION_GUIDE.md`
  antes de tocar el esquema.
- Tests con PHPUnit en [tests/](tests/).

## Frontend — controlcd-app

**Stack:** Quasar Framework 2 + Vue 3 (Composition API), Pinia (+ persistedstate),
Vue Router, Axios, TypeScript parcial, Tailwind, Vuelidate + Zod, Capacitor 7
(Android APK con cámara, geolocalización, biometría), Leaflet + Google Maps.

**Estructura clave:** [src/pages/](../controlcd-app/src/pages/),
[src/components/](../controlcd-app/src/components/),
[src/stores/](../controlcd-app/src/stores/) (Pinia),
[src/services/](../controlcd-app/src/services/) (cliente HTTP a la API),
[src/router/](../controlcd-app/src/router/), [src/schemas/](../controlcd-app/src/schemas/) (Zod).

**Builds por entorno** (`QENV` = development | test | production):
- Web SPA: `npm run dev | testing | prod` y `*Build`
- Android APK: `prodCapacitor`, `testCapacitor`, `buildApk`
- Despliegue: scripts `deploy-*-frontend.sh` y `deploy-*-apk.sh`.

El APK es el canal principal para cobradores en calle: requiere cámara (fotos de
pago/cliente), geolocalización (registro de visitas) y funcionar con conectividad
intermitente — considerar el impacto en cualquier cambio de servicios HTTP.

## Trabajando en este repo

- Antes de modificar cálculos financieros (créditos, cuotas, liquidaciones), leer el
  servicio correspondiente y verificar tests existentes.
- Cualquier cambio que afecte la API debe revisarse contra los consumidores en
  `controlcd-app/src/services/` y `src/stores/`.
- Idioma de commits, comentarios de UI y documentación: español.
