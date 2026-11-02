-- 2.0: várias contas, transferências, vários cartões, pagamento ligado à fatura, estorno e chave das categorias especiais.
-- A parte de dados (a Conta principal com o saldo inicial, as chaves e de que fatura é cada pagamento) fica no .php de
-- mesmo nome, que roda logo depois. Roda com as chaves estrangeiras ligadas: as tabelas recriadas não são referenciadas
-- por nenhuma outra. A ordem das colunas é a do schema.sqlite.sql, porque a restauração de backup compara as duas.

CREATE TABLE contas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT COLLATE AI_CI NOT NULL CHECK (length(nome) <= 60),
  tipo TEXT NOT NULL DEFAULT 'corrente' CHECK (tipo IN ('corrente', 'digital', 'dinheiro', 'outra')),
  saldo_inicial REAL NOT NULL DEFAULT 0 CHECK (abs(saldo_inicial) < 1e10),
  ativa INTEGER NOT NULL DEFAULT 1,
  ordem INTEGER NOT NULL DEFAULT 0,
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em)),
  UNIQUE (nome)
) STRICT;
CREATE TRIGGER dec_contas_saldo_inicial_i AFTER INSERT ON contas WHEN NEW.saldo_inicial IS NOT dec_fix(NEW.saldo_inicial, 2) BEGIN UPDATE contas SET saldo_inicial = dec_fix(NEW.saldo_inicial, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_contas_saldo_inicial_u AFTER UPDATE OF saldo_inicial ON contas WHEN NEW.saldo_inicial IS NOT dec_fix(NEW.saldo_inicial, 2) BEGIN UPDATE contas SET saldo_inicial = dec_fix(NEW.saldo_inicial, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_contas AFTER UPDATE ON contas WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.tipo IS NOT OLD.tipo OR NEW.saldo_inicial IS NOT OLD.saldo_inicial OR NEW.ativa IS NOT OLD.ativa OR NEW.ordem IS NOT OLD.ordem OR NEW.criado_em IS NOT OLD.criado_em) BEGIN UPDATE contas SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

-- a conta que já existia: tudo o que foi lançado até aqui é dela
INSERT INTO contas (id, nome, tipo, saldo_inicial, ordem) VALUES (1, 'Conta principal', 'corrente', COALESCE((SELECT valor FROM ajustes WHERE chave = 'saldo_ini'), 0), 0);

-- cartões: a conta que costuma pagar a fatura e a ordem na tela
ALTER TABLE cartoes ADD COLUMN conta_id INTEGER REFERENCES contas (id);
ALTER TABLE cartoes ADD COLUMN ordem INTEGER NOT NULL DEFAULT 0;
UPDATE cartoes SET conta_id = 1, ordem = id;
DROP TRIGGER upd_cartoes;
CREATE TRIGGER upd_cartoes AFTER UPDATE ON cartoes WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.dia_fechamento IS NOT OLD.dia_fechamento OR NEW.dia_vencimento IS NOT OLD.dia_vencimento OR NEW.limite IS NOT OLD.limite OR NEW.alimenta_plano IS NOT OLD.alimenta_plano OR NEW.ativo IS NOT OLD.ativo OR NEW.criado_em IS NOT OLD.criado_em OR NEW.conta_id IS NOT OLD.conta_id OR NEW.ordem IS NOT OLD.ordem) BEGIN UPDATE cartoes SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

-- lançamentos: a conta, e o cartão e a fatura de um pagamento de fatura. Recriada porque uma coluna nova com
-- REFERENCES e padrão diferente de NULL não entra por ALTER TABLE com as chaves estrangeiras ligadas.
CREATE TABLE lancamentos_novo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  data TEXT NOT NULL CHECK (data IS date(data)),
  descricao TEXT COLLATE AI_CI NOT NULL CHECK (length(descricao) <= 255),
  categoria_id INTEGER NOT NULL REFERENCES categorias (id),
  valor REAL NOT NULL CHECK (abs(valor) < 1e10),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em)),
  conta_id INTEGER NOT NULL DEFAULT 1 REFERENCES contas (id),
  cartao_id INTEGER REFERENCES cartoes (id),
  fatura TEXT CHECK (fatura IS date(fatura) AND (fatura IS NULL OR CAST(strftime('%d', fatura) AS INTEGER) = 1)),
  CONSTRAINT chk_valor_positivo CHECK (valor > 0),
  CONSTRAINT chk_lanc_fatura CHECK ((cartao_id IS NULL) = (fatura IS NULL))
) STRICT;
INSERT INTO lancamentos_novo (id, data, descricao, categoria_id, valor, usuario_id, criado_em, atualizado_em)
  SELECT id, data, descricao, categoria_id, valor, usuario_id, criado_em, atualizado_em FROM lancamentos;
-- o contador continua de onde estava: um id apagado não volta
INSERT INTO sqlite_sequence (name, seq) SELECT 'lancamentos_novo', 0 WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'lancamentos_novo');
UPDATE sqlite_sequence SET seq = MAX(seq, COALESCE((SELECT seq FROM sqlite_sequence WHERE name = 'lancamentos'), 0)) WHERE name = 'lancamentos_novo';
DROP TABLE lancamentos;
ALTER TABLE lancamentos_novo RENAME TO lancamentos;
CREATE INDEX idx_lanc_usuario ON lancamentos (usuario_id);
CREATE INDEX idx_data ON lancamentos (data);
CREATE INDEX idx_categoria_data ON lancamentos (categoria_id, data);
CREATE INDEX idx_lanc_conta_data ON lancamentos (conta_id, data);
CREATE INDEX idx_lanc_cartao_fatura ON lancamentos (cartao_id, fatura);
CREATE TRIGGER dec_lancamentos_valor_i AFTER INSERT ON lancamentos WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE lancamentos SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_lancamentos_valor_u AFTER UPDATE OF valor ON lancamentos WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE lancamentos SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_lancamentos AFTER UPDATE ON lancamentos WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.data IS NOT OLD.data OR NEW.descricao IS NOT OLD.descricao OR NEW.categoria_id IS NOT OLD.categoria_id OR NEW.valor IS NOT OLD.valor OR NEW.usuario_id IS NOT OLD.usuario_id OR NEW.criado_em IS NOT OLD.criado_em OR NEW.conta_id IS NOT OLD.conta_id OR NEW.cartao_id IS NOT OLD.cartao_id OR NEW.fatura IS NOT OLD.fatura) BEGIN UPDATE lancamentos SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

-- transferências entre contas: fora das categorias, não são renda nem gasto, e o plano não muda
CREATE TABLE transferencias (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  data TEXT NOT NULL CHECK (data IS date(data)),
  de_conta_id INTEGER NOT NULL REFERENCES contas (id),
  para_conta_id INTEGER NOT NULL REFERENCES contas (id),
  valor REAL NOT NULL CHECK (abs(valor) < 1e10),
  descricao TEXT COLLATE AI_CI NOT NULL DEFAULT '' CHECK (length(descricao) <= 255),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  CONSTRAINT chk_transf_contas CHECK (de_conta_id <> para_conta_id),
  CONSTRAINT chk_transf_valor CHECK (valor > 0)
) STRICT;
CREATE INDEX idx_transf_data ON transferencias (data);
CREATE INDEX fk_transf_de ON transferencias (de_conta_id);
CREATE INDEX fk_transf_para ON transferencias (para_conta_id);
CREATE INDEX fk_transf_usuario ON transferencias (usuario_id);
CREATE TRIGGER dec_transferencias_valor_i AFTER INSERT ON transferencias WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE transferencias SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_transferencias_valor_u AFTER UPDATE OF valor ON transferencias WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE transferencias SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;

-- compras no cartão: o estorno é negativo, sempre em 1 parcela
CREATE TABLE cartao_compras_novo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cartao_id INTEGER NOT NULL REFERENCES cartoes (id),
  data TEXT NOT NULL CHECK (data IS date(data)),
  descricao TEXT COLLATE AI_CI NOT NULL CHECK (length(descricao) <= 255),
  categoria_id INTEGER REFERENCES categorias (id),
  valor_total REAL NOT NULL CHECK (abs(valor_total) < 1e10),
  parcelas INTEGER NOT NULL DEFAULT 1,
  primeira_fatura TEXT NOT NULL CHECK (primeira_fatura IS date(primeira_fatura)),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em)),
  CONSTRAINT chk_cc_parcelas CHECK (parcelas BETWEEN 1 AND 48),
  CONSTRAINT chk_cc_primeira CHECK (CAST(strftime('%d', primeira_fatura) AS INTEGER) = 1),
  CONSTRAINT chk_cc_valor CHECK (valor_total <> 0),
  CONSTRAINT chk_cc_estorno CHECK (valor_total > 0 OR parcelas = 1)
) STRICT;
INSERT INTO cartao_compras_novo (id, cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id, criado_em, atualizado_em)
  SELECT id, cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id, criado_em, atualizado_em FROM cartao_compras;
INSERT INTO sqlite_sequence (name, seq) SELECT 'cartao_compras_novo', 0 WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'cartao_compras_novo');
UPDATE sqlite_sequence SET seq = MAX(seq, COALESCE((SELECT seq FROM sqlite_sequence WHERE name = 'cartao_compras'), 0)) WHERE name = 'cartao_compras_novo';
DROP TABLE cartao_compras;
ALTER TABLE cartao_compras_novo RENAME TO cartao_compras;
CREATE INDEX idx_cc_cartao_fatura ON cartao_compras (cartao_id, primeira_fatura);
CREATE INDEX cc_categoria ON cartao_compras (categoria_id);
CREATE INDEX cc_usuario ON cartao_compras (usuario_id);
CREATE TRIGGER dec_cartao_compras_valor_total_i AFTER INSERT ON cartao_compras WHEN NEW.valor_total IS NOT dec_fix(NEW.valor_total, 2) BEGIN UPDATE cartao_compras SET valor_total = dec_fix(NEW.valor_total, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_cartao_compras_valor_total_u AFTER UPDATE OF valor_total ON cartao_compras WHEN NEW.valor_total IS NOT dec_fix(NEW.valor_total, 2) BEGIN UPDATE cartao_compras SET valor_total = dec_fix(NEW.valor_total, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_cartao_compras AFTER UPDATE ON cartao_compras WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.cartao_id IS NOT OLD.cartao_id OR NEW.data IS NOT OLD.data OR NEW.descricao IS NOT OLD.descricao OR NEW.categoria_id IS NOT OLD.categoria_id OR NEW.valor_total IS NOT OLD.valor_total OR NEW.parcelas IS NOT OLD.parcelas OR NEW.primeira_fatura IS NOT OLD.primeira_fatura OR NEW.usuario_id IS NOT OLD.usuario_id OR NEW.criado_em IS NOT OLD.criado_em) BEGIN UPDATE cartao_compras SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

-- conferências com o banco: de uma conta, ou do total (NULL, como todas as de antes)
ALTER TABLE saldo_conferencias ADD COLUMN conta_id INTEGER REFERENCES contas (id);
CREATE INDEX fk_saldo_conta ON saldo_conferencias (conta_id);

-- categorias especiais: o motor e o Cartão as acham pela chave, e o nome pode mudar
ALTER TABLE categorias ADD COLUMN chave TEXT CHECK (length(chave) <= 24);
CREATE UNIQUE INDEX uq_categorias_chave ON categorias (chave);
