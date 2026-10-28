-- =====================================================================
-- PASO 2 de 2 — APLICAR (solo si el paso 1 dio: -5, vacío, y el número de filas esperado)
-- =====================================================================
-- Correr con:  mysql -u <usuario> -p -t controlcd_prod < fix_transfer_updated_at_20261021_2_aplicar.sql
--
-- Solo cambia credits.updated_at de los créditos del respaldo; no toca estado,
-- saldos, pagos, cuotas ni liquidaciones. Ningún cálculo de caja usa updated_at
-- de créditos Liquidado (solo de 'Cartera Irrecuperable').
--
-- ROLLBACK (deja todo exactamente como estaba):
--   UPDATE credits c JOIN credits_updated_at_bkp_20261021 b ON b.credit_id = c.id
--   SET c.updated_at = b.old_updated_at;
-- =====================================================================

START TRANSACTION;
UPDATE credits c
JOIN credits_updated_at_bkp_20261021 b ON b.credit_id = c.id
SET c.updated_at = b.new_updated_at
WHERE c.updated_at = b.old_updated_at;
SELECT ROW_COUNT() AS filas_actualizadas;  -- debe coincidir con "filas" del paso 1
COMMIT;

-- Verificación: debe dar 0.
SELECT COUNT(*) AS liquidados_aun_pisados
FROM credits c
JOIN clients cl ON cl.id = c.client_id
WHERE c.status = 'Liquidado' AND c.deleted_at IS NULL AND cl.transferred_at IS NOT NULL
  AND ABS(TIMESTAMPDIFF(SECOND, c.updated_at, cl.transferred_at)) <= 2;
