-- O cartão com as colunas da 1.14 (visual, s0004) e da 2.0 (conta_id e ordem, s0001), por qualquer caminho.
-- Um site da 1.14 rodou a s0004 antes da s0001, e um mais antigo roda a s0001 antes: a ordem das colunas e o gatilho
-- de atualizado_em ficavam diferentes, cada um sem as colunas da outra. A restauração de um backup compara as
-- colunas na ordem, então o visual vai para o fim nos dois casos (sai e volta, com o valor de cada cartão), e o
-- gatilho passa a olhar todas as colunas.
DROP TRIGGER IF EXISTS upd_cartoes;
CREATE TEMP TABLE cartoes_visual AS SELECT id, visual FROM cartoes;
ALTER TABLE cartoes DROP COLUMN visual;
ALTER TABLE cartoes ADD COLUMN visual TEXT CHECK (length(visual) <= 24);
UPDATE cartoes SET visual = (SELECT v.visual FROM temp.cartoes_visual v WHERE v.id = cartoes.id);
DROP TABLE temp.cartoes_visual;
CREATE TRIGGER upd_cartoes AFTER UPDATE ON cartoes WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.dia_fechamento IS NOT OLD.dia_fechamento OR NEW.dia_vencimento IS NOT OLD.dia_vencimento OR NEW.limite IS NOT OLD.limite OR NEW.alimenta_plano IS NOT OLD.alimenta_plano OR NEW.ativo IS NOT OLD.ativo OR NEW.criado_em IS NOT OLD.criado_em OR NEW.conta_id IS NOT OLD.conta_id OR NEW.ordem IS NOT OLD.ordem OR NEW.visual IS NOT OLD.visual) BEGIN UPDATE cartoes SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;
