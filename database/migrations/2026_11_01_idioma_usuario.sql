-- O idioma de cada usuário fica na conta. NULL = ainda não escolheu: segue o cookie e o navegador.
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'idioma');
SET @sql := IF(@tem = 0, 'ALTER TABLE usuarios ADD COLUMN idioma VARCHAR(5) NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
