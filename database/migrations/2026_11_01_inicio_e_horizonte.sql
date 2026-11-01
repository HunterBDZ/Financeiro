-- Início do plano numa data de verdade, meses sem fim e recursos opcionais.
-- Para quem já usa o app: o início é o 1º mês que existe, a meta continua no último mês de hoje e nada muda.
INSERT IGNORE INTO ajustes (chave, label, valor, confirmado)
  SELECT 'data_inicio', 'Início do plano (AAAAMMDD)', CAST(DATE_FORMAT(MIN(mes), '%Y%m01') AS UNSIGNED), 1 FROM meses;
INSERT IGNORE INTO ajustes (chave, label, valor, confirmado)
  SELECT 'meta_mes', 'Mês da meta (AAAAMM)', IF((SELECT valor FROM ajustes WHERE chave = 'meta_final') > 0, CAST(DATE_FORMAT(MAX(mes), '%Y%m') AS UNSIGNED), 0), 1 FROM meses;
INSERT IGNORE INTO ajustes (chave, label, valor, confirmado) VALUES
  ('configurado', 'Primeira configuração feita', 1, 1),
  ('usa_emprestimo', 'Usa a aba Empréstimo', 1, 1),
  ('usa_cartao', 'Usa a aba Cartão', 1, 1),
  ('parcela_modo', 'Parcela do empréstimo: 1 descontada do salário, 2 paga à parte', 1, 1);

UPDATE ajustes SET label = 'Saldo da conta no início' WHERE chave = 'saldo_ini';
UPDATE ajustes SET label = 'Poupança no início' WHERE chave = 'poupanca_ini';
UPDATE ajustes SET label = 'Investimentos no início' WHERE chave = 'investimentos_ini';
UPDATE ajustes SET label = 'Meta de conta e poupança' WHERE chave = 'meta_final';
UPDATE ajustes SET label = 'Parcela mensal' WHERE chave = 'parcela_mensal';
UPDATE ajustes SET label = 'Mês da 1ª parcela do empréstimo (AAAAMM)' WHERE chave = 'consignado_inicio';

INSERT IGNORE INTO categorias (nome, grupo, sinal, ordem) VALUES ('Parcela do empréstimo', 'Emprestimo', -1, 3);
