SELECT
    c.seller_id,
    u.name                                        AS vendedor,
    s.company_id,
    COUNT(*)                                      AS creditos_desfasados,
    ROUND(SUM(c.remaining_amount), 2)             AS declarado_actual,
    ROUND(SUM(GREATEST(COALESCE(i.deuda,0) - COALESCE(p.abono,0), 0)), 2) AS real_calculado,
    ROUND(SUM(GREATEST(COALESCE(i.deuda,0) - COALESCE(p.abono,0), 0) - c.remaining_amount), 2) AS ajuste_neto,
    ROUND(MAX(ABS(GREATEST(COALESCE(i.deuda,0) - COALESCE(p.abono,0), 0) - c.remaining_amount)), 2) AS ajuste_max
FROM credits c
LEFT JOIN sellers s ON s.id = c.seller_id
LEFT JOIN users   u ON u.id = s.user_id
LEFT JOIN (SELECT credit_id, SUM(quota_amount - paid_amount) AS deuda
           FROM installments WHERE deleted_at IS NULL GROUP BY credit_id) i
       ON i.credit_id = c.id
LEFT JOIN (SELECT credit_id, SUM(unapplied_amount) AS abono
           FROM payments WHERE deleted_at IS NULL GROUP BY credit_id) p
       ON p.credit_id = c.id
WHERE c.deleted_at IS NULL
  AND ABS(c.remaining_amount - GREATEST(COALESCE(i.deuda,0) - COALESCE(p.abono,0), 0)) > 0.01
  AND c.id NOT IN (820, 26441, 26560, 26960, 26241, 24183)
GROUP BY c.seller_id, u.name, s.company_id
ORDER BY ABS(SUM(GREATEST(COALESCE(i.deuda,0) - COALESCE(p.abono,0), 0) - c.remaining_amount)) DESC;
