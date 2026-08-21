-- SOLO SI HAY QUE DESHACER UNA RUTA. Devuelve credits al estado exacto previo.
-- Igual que el 5: no se toca updated_at (ver el comentario de ese archivo).
UPDATE credits c
JOIN credits_recalc_audit a ON a.id = c.id
SET c.remaining_amount = a.remaining_antes,
    c.status           = a.status_antes,
    a.aplicado         = 0
WHERE a.seller_id = 16;                                       -- <<< RUTA A DESHACER
