-- El detalle credito por credito, los ajustes mas grandes primero.
SELECT * FROM credits_recalc_audit
WHERE aplicado = 0
ORDER BY ABS(remaining_nuevo - remaining_antes) DESC;
