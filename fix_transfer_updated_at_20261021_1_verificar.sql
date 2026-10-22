-- =====================================================================
-- PASO 1 de 2 — VERIFICAR Y RESPALDAR (no modifica credits)
-- Reparación: credits.updated_at pisado por la transferencia de clientes
-- =====================================================================
-- Bug: ClientController::transfer / transferMassive movían credits.seller_id con
-- un update de Eloquent que también ponía updated_at = now(). La liquidación
-- lista como "Liquidado hoy" a todo crédito Liquidado con updated_at de ese día,
-- y la cartera liquidada del cliente aparecía en la liquidación del vendedor
-- destino (AlejandroRene 2026-10-21: 1.314 créditos en vez de 302).
--
-- PRERREQUISITO: el fix de ClientController (toBase()) ya desplegado.
-- Correr con:  mysql -u <usuario> -p -t controlcd_prod < fix_transfer_updated_at_20261021_1_verificar.sql
--
-- REVISAR LA SALIDA ANTES DEL PASO 2:
--   a) horas_desfase: la primera fila debe ser -5.   Si no, NO seguir.
--   b) otros estados afectados: debe salir vacío.     Si sale algo, NO seguir.
--   c) filas: cuántos créditos se corregirán (local 2026-10-21: 1.803).
-- =====================================================================

-- a) Zona horaria: en Liquidado NO afectados, updated_at - último pago ≈ -5 h (Lima).
SELECT ROUND(TIMESTAMPDIFF(SECOND, lp.ult, c.updated_at) / 3600) AS horas_desfase, COUNT(*) AS n
FROM credits c
JOIN clients cl ON cl.id = c.client_id
JOIN (SELECT credit_id, MAX(created_at) AS ult FROM payments WHERE deleted_at IS NULL GROUP BY credit_id) lp
  ON lp.credit_id = c.id
WHERE c.status = 'Liquidado' AND c.deleted_at IS NULL
  AND (cl.transferred_at IS NULL OR ABS(TIMESTAMPDIFF(SECOND, c.updated_at, cl.transferred_at)) > 2)
GROUP BY 1 ORDER BY n DESC LIMIT 3;

-- b) Otros estados cerrados afectados: este script NO los toca. Debe salir vacío.
SELECT c.status, COUNT(*) AS n
FROM credits c
JOIN clients cl ON cl.id = c.client_id
WHERE c.status IN ('Renovado', 'Unificado', 'Cartera Irrecuperable')
  AND c.deleted_at IS NULL AND cl.transferred_at IS NOT NULL
  AND ABS(TIMESTAMPDIFF(SECOND, c.updated_at, cl.transferred_at)) <= 2
GROUP BY c.status;

-- Respaldo con valor viejo y nuevo. Falla si ya existe (no se corre dos veces).
CREATE TABLE credits_updated_at_bkp_20261021 AS
SELECT c.id AS credit_id,
       c.updated_at AS old_updated_at,
       DATE_SUB(lp.ult, INTERVAL 5 HOUR) AS new_updated_at,
       cl.transferred_at AS client_transferred_at
FROM credits c
JOIN clients cl ON cl.id = c.client_id
JOIN (SELECT credit_id, MAX(created_at) AS ult FROM payments WHERE deleted_at IS NULL GROUP BY credit_id) lp
  ON lp.credit_id = c.id
WHERE c.status = 'Liquidado'
  AND c.deleted_at IS NULL
  AND cl.transferred_at IS NOT NULL
  AND ABS(TIMESTAMPDIFF(SECOND, c.updated_at, cl.transferred_at)) <= 2
  AND DATE_SUB(lp.ult, INTERVAL 5 HOUR) < c.updated_at;

ALTER TABLE credits_updated_at_bkp_20261021 ADD PRIMARY KEY (credit_id);

-- c) Resumen de lo que corregirá el paso 2.
SELECT COUNT(*) AS filas, MIN(new_updated_at) AS desde, MAX(new_updated_at) AS hasta
FROM credits_updated_at_bkp_20261021;
