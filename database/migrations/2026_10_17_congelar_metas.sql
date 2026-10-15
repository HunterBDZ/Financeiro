-- 2026-10-17 · revisão da 2026_10_16: mês fechado não muda quando a meta muda.
--
-- Rodar como root ANTES de publicar o código (idempotente):
--   sudo mysql financeiro < database/migrations/2026_10_17_congelar_metas.sql
--
-- 1) A meta só vale como previsão padrão em mês ainda em PREVISAO. Ao fechar um mês, o
--    MesController grava antes (PrevisaoLote::congelar, origem 'congelar') a previsão que vinha
--    da meta como linhas do próprio mês; reabrir desfaz esse lote (descongela).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW previsao_efetiva AS
SELECT c.id AS categoria_id,
       m.mes AS mes,
       CAST(COALESCE(p.valor,
                     CASE WHEN m.situacao = 'PREVISAO' AND c.ativo = 1 AND c.operacional = 1 AND c.sinal = -1 THEN mt.valor END,
                     0) AS DECIMAL(12,2)) AS valor,
       CASE WHEN p.id IS NOT NULL THEN 'mes'
            WHEN mt.valor IS NOT NULL AND m.situacao = 'PREVISAO' AND c.ativo = 1 AND c.operacional = 1 AND c.sinal = -1 THEN 'meta'
       END AS fonte
FROM categorias c
CROSS JOIN meses m
LEFT JOIN previsao p ON p.categoria_id = c.id AND p.mes = m.mes
LEFT JOIN metas mt ON mt.categoria_id = c.id;

-- 2) origem dos lotes de congelamento (fora do "desfazer última alteração")
ALTER TABLE previsao_lotes MODIFY origem ENUM('manual','propagar','sugestao','fechar','migracao','congelar') NOT NULL;
