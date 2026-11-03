-- O visual do cartão na aba Cartão: a chave de um estilo (Services\CartaoVisual) ou uma cor própria #rrggbb.
-- NULL é o padrão, que segue o tema. O gatilho de atualizado_em passa a olhar também essa coluna.
ALTER TABLE cartoes ADD COLUMN visual TEXT CHECK (length(visual) <= 24);
DROP TRIGGER IF EXISTS upd_cartoes;
CREATE TRIGGER upd_cartoes AFTER UPDATE ON cartoes WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.dia_fechamento IS NOT OLD.dia_fechamento OR NEW.dia_vencimento IS NOT OLD.dia_vencimento OR NEW.limite IS NOT OLD.limite OR NEW.alimenta_plano IS NOT OLD.alimenta_plano OR NEW.ativo IS NOT OLD.ativo OR NEW.criado_em IS NOT OLD.criado_em OR NEW.visual IS NOT OLD.visual) BEGIN UPDATE cartoes SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;
