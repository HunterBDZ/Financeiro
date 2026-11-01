-- O lançamento do crédito do empréstimo vinha da importação da planilha, com texto específico.
-- Rodar de novo não muda nada.
UPDATE lancamentos l
JOIN categorias c ON c.id = l.categoria_id
SET l.descricao = 'Crédito do empréstimo'
WHERE c.nome = 'Crédito do empréstimo'
  AND l.descricao LIKE 'Crédito do empréstimo % (valor em Ajustes)';
