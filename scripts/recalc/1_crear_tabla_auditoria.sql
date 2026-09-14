-- UNA SOLA VEZ EN LA BASE. Crea el respaldo/reversa. No borra nada si ya existe.
CREATE TABLE IF NOT EXISTS credits_recalc_audit (
    id              BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    seller_id       BIGINT UNSIGNED NULL,
    remaining_antes DECIMAL(15,2)   NULL,
    remaining_nuevo DECIMAL(15,2)   NULL,
    status_antes    VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    status_nuevo    VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    cuotas_vivas    INT             NULL,
    aplicado        TINYINT(1)      NOT NULL DEFAULT 0,
    creado_en       DATETIME        NULL,
    KEY idx_seller_aplicado (seller_id, aplicado)
);
