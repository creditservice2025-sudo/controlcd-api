SELECT
    COUNT(*)                                                AS creditos_a_recalcular,
    SUM(CASE WHEN c.status = 'Vigente' THEN 1 ELSE 0 END)   AS vigentes,
    SUM(CASE WHEN c.status = 'Liquidado' THEN 1 ELSE 0 END) AS liquidados,
    SUM(CASE WHEN c.status = 'Cartera Irrecuperable' THEN 1 ELSE 0 END) AS irrecuperables,
    ROUND(SUM(
        CASE WHEN COALESCE(inst.sum_quota, 0) > 0
             THEN inst.sum_quota
             ELSE c.credit_value + (c.credit_value * c.total_interest / 100)
        END
        - c.total_amount
    ), 2)                                                   AS total_amount_recuperado,
    ROUND(SUM(COALESCE(inst.real_remaining, 0) - c.remaining_amount), 2)
                                                            AS remaining_recuperado,
    SUM(CASE
        WHEN ABS(
            (c.credit_value + (c.credit_value * c.total_interest / 100))
            - COALESCE(inst.sum_quota, 0)
        ) > 0.01 THEN 1 ELSE 0
    END)                                                    AS con_divergencia,
    SUM(CASE WHEN COALESCE(inst.sum_quota, 0) = 0 THEN 1 ELSE 0 END)
                                                            AS sin_installments
FROM credits c
LEFT JOIN (
    SELECT
        credit_id,
        ROUND(SUM(quota_amount), 2)                    AS sum_quota,
        ROUND(SUM(quota_amount - paid_amount), 2)      AS real_remaining,
        SUM(CASE WHEN status <> 'Pagado' THEN 1 ELSE 0 END) AS unpaid_count
    FROM installments
    WHERE deleted_at IS NULL
    GROUP BY credit_id
) inst ON inst.credit_id = c.id
WHERE c.deleted_at IS NULL
  AND c.total_amount = 0
  AND c.credit_value > 0;
