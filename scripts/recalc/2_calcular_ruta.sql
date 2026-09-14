-- UNICO ARCHIVO DONDE SE CAMBIA EL VENDEDOR. Buscar los 3 numeros marcados
-- con <<< y editarlos. Solo LEE credits/installments/payments.
INSERT INTO credits_recalc_audit
    (id, seller_id, remaining_antes, remaining_nuevo,
     status_antes, status_nuevo, cuotas_vivas, aplicado, creado_en)
SELECT
    x.id, x.seller_id, x.remaining_antes, x.remaining_nuevo, x.status_antes,
    CASE
        WHEN x.status_antes IN ('Renovado','Cartera Irrecuperable',
                                'Irrecuperable','Inactivo','Unificado')
             THEN x.status_antes
        WHEN x.remaining_nuevo <= 0.001 AND x.cuotas_vivas = 0 THEN 'Liquidado'
        WHEN x.status_antes = 'Liquidado'                      THEN 'Vigente'
        ELSE x.status_antes
    END AS status_nuevo,
    x.cuotas_vivas, 0, NOW()
FROM (
    SELECT
        c.id,
        c.seller_id,
        c.remaining_amount AS remaining_antes,
        GREATEST(
            COALESCE((SELECT SUM(i.quota_amount - i.paid_amount) FROM installments i
                       WHERE i.credit_id = c.id AND i.deleted_at IS NULL), 0)
          - COALESCE((SELECT SUM(p.unapplied_amount)             FROM payments p
                       WHERE p.credit_id = c.id AND p.deleted_at IS NULL), 0)
        , 0) AS remaining_nuevo,
        c.status AS status_antes,
        (SELECT COUNT(*) FROM installments i2
          WHERE i2.credit_id = c.id AND i2.deleted_at IS NULL
            AND i2.status <> 'Pagado') AS cuotas_vivas
    FROM credits c
    WHERE c.deleted_at IS NULL
      AND c.seller_id = 16                                    -- <<< ID DEL VENDEDOR
) x
WHERE ABS(x.remaining_antes - x.remaining_nuevo) > 0.01
  AND x.id NOT IN (820, 26441, 26560, 26960, 26241, 24183)    -- <<< cuarentena: NO tocar
  AND ABS(x.remaining_antes - x.remaining_nuevo) <= 999999999  -- <<< tope por credito
ON DUPLICATE KEY UPDATE
    seller_id       = VALUES(seller_id),
    remaining_antes = VALUES(remaining_antes),
    remaining_nuevo = VALUES(remaining_nuevo),
    status_antes    = VALUES(status_antes),
    status_nuevo    = VALUES(status_nuevo),
    cuotas_vivas    = VALUES(cuotas_vivas),
    aplicado        = 0,
    creado_en       = VALUES(creado_en);
