-- Script para insertar versiones iniciales
-- Ejecutar esto en tu base de datos (Staging y Producción)

-- 1. STAGING (Android)
INSERT INTO app_versions (platform, environment, min_version, latest_version, force_update, store_url, release_notes, created_at, updated_at)
VALUES ('android', 'staging', '1.0.0', '1.0.0', false, 'https://staging.control-cd.com/apk/ControlCD-Staging.apk', 'Versión inicial de pruebas', NOW(), NOW())
ON DUPLICATE KEY UPDATE latest_version = '1.0.0', updated_at = NOW();

-- 2. PRODUCTION (Android)
INSERT INTO app_versions (platform, environment, min_version, latest_version, force_update, store_url, release_notes, created_at, updated_at)
VALUES ('android', 'production', '1.0.0', '1.0.0', false, 'https://control-cd.com/apk/ControlCD.apk', 'Versión inicial de producción', NOW(), NOW())
ON DUPLICATE KEY UPDATE latest_version = '1.0.0', updated_at = NOW();

-- NOTA: Para forzar actualización en el futuro:
-- UPDATE app_versions SET min_version = '1.0.5', latest_version = '1.0.5', force_update = true WHERE platform = 'android' AND environment = 'staging';
