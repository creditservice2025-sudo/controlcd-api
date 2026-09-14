-- Recalcula desde cero y compara contra lo que quedo en credits. DEBE DAR 0 FILAS.
SELECT c.id, c.seller_id, c.status, c.remaining_amount AS quedo,
       GREATEST(
           COALESCE((SELECT SUM(i.quota_amount - i.paid_amount) FROM installments i
                      WHERE i.credit_id = c.id AND i.deleted_at IS NULL), 0)
         - COALESCE((SELECT SUM(p.unapplied_amount)             FROM payments p
                      WHERE p.credit_id = c.id AND p.deleted_at IS NULL), 0)
       , 0) AS real_calculado
FROM credits c
JOIN credits_recalc_audit a ON a.id = c.id AND a.aplicado = 1
WHERE ABS(c.remaining_amount - a.remaining_nuevo) > 0.01;
