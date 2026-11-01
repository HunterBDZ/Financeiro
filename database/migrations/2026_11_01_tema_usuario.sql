-- O tema de cada usuário fica na conta. NULL = automático: segue o aparelho, entre Claro e Escuro.
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'tema');
SET @sql := IF(@tem = 0, 'ALTER TABLE usuarios ADD COLUMN tema VARCHAR(24) NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
