-- 2026-10-16 · a meta vira a previsão padrão de todo mês (decisão do casal: opção B)
--
-- Rodar como root ANTES de publicar o código (idempotente, pode rodar de novo):
--   sudo mysql financeiro < database/migrations/2026_10_16_meta_padrao.sql
-- Depois de publicar, a parte de dados: database/migrations/2026_10_16_meta_padrao.php

-- 1) Previsão efetiva de cada categoria × mês — a ÚNICA definição da regra:
--    o valor gravado no mês (exceção) ou, sem linha, a meta da categoria (só gasto ativo);
--    senão 0. fonte: 'mes' (linha em previsao) · 'meta' · NULL (nada previsto).
--    Quem lê previsão lê daqui; quem grava continua gravando em `previsao` (PrevisaoLote).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW previsao_efetiva AS
SELECT c.id AS categoria_id,
       m.mes AS mes,
       CAST(COALESCE(p.valor,
                     CASE WHEN c.ativo = 1 AND c.operacional = 1 AND c.sinal = -1 THEN mt.valor END,
                     0) AS DECIMAL(12,2)) AS valor,
       CASE WHEN p.id IS NOT NULL THEN 'mes'
            WHEN mt.valor IS NOT NULL AND c.ativo = 1 AND c.operacional = 1 AND c.sinal = -1 THEN 'meta'
       END AS fonte
FROM categorias c
CROSS JOIN meses m
LEFT JOIN previsao p ON p.categoria_id = c.id AND p.mes = m.mes
LEFT JOIN metas mt ON mt.categoria_id = c.id;

-- 2) "voltar à meta" apaga a linha do mês: o log guarda depois = NULL (e o desfazer a recria)
ALTER TABLE previsao_log MODIFY depois DECIMAL(12,2) NULL;

-- 3) origem dos lotes da migração de dados (2026_10_16_meta_padrao.php)
ALTER TABLE previsao_lotes MODIFY origem ENUM('manual','propagar','sugestao','fechar','migracao') NOT NULL;
