-- O visual do cartão na aba Cartão: a chave de um estilo (Services\CartaoVisual) ou uma cor própria #rrggbb.
-- NULL é o padrão, que segue o tema.
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cartoes' AND COLUMN_NAME = 'visual');
SET @sql := IF(@tem = 0, 'ALTER TABLE cartoes ADD COLUMN visual VARCHAR(24) NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
