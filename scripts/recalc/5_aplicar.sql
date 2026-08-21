-- APLICA. Escribe en credits y marca la corrida como aplicada, todo junto.
--
-- OJO: NO se toca credits.updated_at a proposito. El reporte de liquidacion y
-- el dashboard detectan los "irrecuperables de hoy" con
--   credits.status = 'Cartera Irrecuperable' AND credits.updated_at = hoy
-- y ese monto se RESTA de la caja del dia. Si el recalculo sellara updated_at
-- con NOW(), cientos de creditos viejos entrarian como irrecuperables de hoy
-- y le descontarian plata a la caja de rutas que no hicieron nada.
-- La trazabilidad de que se toco y cuando queda en credits_recalc_audit.
UPDATE credits c
JOIN credits_recalc_audit a ON a.id = c.id AND a.aplicado = 0
SET c.remaining_amount = a.remaining_nuevo,
    c.status           = a.status_nuevo,
    a.aplicado         = 1;
