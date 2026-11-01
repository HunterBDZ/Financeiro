-- Aba Investimentos: um grupo novo que, como a poupança, não conta como renda nem gasto,
-- as categorias de aplicar e resgatar e o valor de partida.
SET @tipo := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorias' AND COLUMN_NAME = 'grupo');
SET @sql := IF(@tipo LIKE '%Investimento%', 'DO 0',
  'ALTER TABLE categorias
     MODIFY grupo enum(''Entradas'',''Fixos'',''Dia a dia'',''Cartao'',''Ano'',''Emprestimo'',''Poupanca'',''Investimento'') NOT NULL,
     MODIFY operacional tinyint(1) GENERATED ALWAYS AS ((case when (grupo in (''Emprestimo'',''Poupanca'',''Investimento'')) then 0 else 1 end)) STORED');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

INSERT IGNORE INTO categorias (nome, grupo, sinal, ordem) VALUES
  ('Aplicar em investimentos', 'Investimento', -1, 1),
  ('Resgate de investimentos', 'Investimento', 1, 2);

INSERT IGNORE INTO ajustes (chave, label, valor)
  SELECT 'investimentos_ini', REPLACE(label, 'Poupança', 'Investimentos'), 0 FROM ajustes WHERE chave = 'poupanca_ini';
