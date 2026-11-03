-- Mês fechado não muda: o saldo inicial de uma conta criada quando já há mês fechado conta a partir do 1º dia do
-- primeiro mês aberto (ContaModel::salvar), e não desde o início do plano. NULL = desde o início, como as contas de
-- antes. O gatilho de atualizado_em passa a olhar também essa coluna.
ALTER TABLE contas ADD COLUMN saldo_inicial_em TEXT CHECK (saldo_inicial_em IS date(saldo_inicial_em));
DROP TRIGGER IF EXISTS upd_contas;
CREATE TRIGGER upd_contas AFTER UPDATE ON contas WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.tipo IS NOT OLD.tipo OR NEW.saldo_inicial IS NOT OLD.saldo_inicial OR NEW.ativa IS NOT OLD.ativa OR NEW.ordem IS NOT OLD.ordem OR NEW.criado_em IS NOT OLD.criado_em OR NEW.saldo_inicial_em IS NOT OLD.saldo_inicial_em) BEGIN UPDATE contas SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;
