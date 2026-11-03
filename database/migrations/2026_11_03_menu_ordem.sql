-- Ordem das abas do menu, por conta: JSON com as chaves das abas (Financeiro\Menu). NULL = a ordem padrão.
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'menu_ordem');
SET @sql := IF(@tem = 0, 'ALTER TABLE usuarios ADD COLUMN menu_ordem VARCHAR(1000) NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
