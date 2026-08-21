-- PREVIA. Lo que se va a cambiar cuando corras el 5. Si "ajuste_max" es un
-- numero absurdo, eso es basura de import, no plata: bajar el tope en el 2.
SELECT COUNT(*)                                             AS creditos_a_corregir,
       ROUND(SUM(remaining_antes), 2)                       AS declarado_hoy,
       ROUND(SUM(remaining_nuevo), 2)                       AS real_calculado,
       ROUND(SUM(remaining_nuevo - remaining_antes), 2)     AS ajuste_neto,
       ROUND(MAX(ABS(remaining_nuevo - remaining_antes)), 2) AS ajuste_max,
       SUM(status_antes <> status_nuevo)                    AS cambian_de_status
FROM credits_recalc_audit
WHERE aplicado = 0;
