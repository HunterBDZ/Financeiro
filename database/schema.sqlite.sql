-- Schema do SQLite: a fonte de instalações novas. Mesmas tabelas, colunas e regras de database/schema.sql.
-- Precisa da conexão do app (src/SqliteConexao.php): a colação AI_CI e as funções agora_local() e dec_fix() são dela.
-- Tabelas STRICT recusam tipo errado como o MySQL em modo estrito. Os CHECK de data e de tamanho fazem o papel de
-- DATE e VARCHAR(n); os gatilhos dec_ fazem o de DECIMAL(n,m), e os gatilhos upd_, o de ON UPDATE CURRENT_TIMESTAMP.
-- Datas e horas ficam em texto, no fuso do site. Dinheiro fica em REAL, com a precisão garantida pelos gatilhos.

CREATE TABLE usuarios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT COLLATE AI_CI NOT NULL CHECK (length(nome) <= 100),
  login TEXT COLLATE AI_CI NOT NULL CHECK (length(login) <= 100),
  senha_hash TEXT NOT NULL CHECK (length(senha_hash) <= 255),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  ultimo_login TEXT CHECK (ultimo_login IS datetime(ultimo_login)),
  sessao_versao INTEGER NOT NULL DEFAULT 0 CHECK (sessao_versao >= 0),
  tema TEXT CHECK (length(tema) <= 24),
  idioma TEXT CHECK (length(idioma) <= 5),
  duas_etapas_segredo TEXT CHECK (length(duas_etapas_segredo) <= 255),
  duas_etapas_passo INTEGER CHECK (duas_etapas_passo >= 0),
  menu_ordem TEXT CHECK (length(menu_ordem) <= 1000),
  UNIQUE (login)
) STRICT;

CREATE TABLE ajustes (
  chave TEXT NOT NULL PRIMARY KEY CHECK (length(chave) <= 50),
  label TEXT COLLATE AI_CI NOT NULL CHECK (length(label) <= 255),
  valor REAL NOT NULL CHECK (abs(valor) < 1e10),
  confirmado INTEGER NOT NULL DEFAULT 0,
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em))
) STRICT;
CREATE TRIGGER dec_ajustes_valor_i AFTER INSERT ON ajustes WHEN NEW.valor IS NOT dec_fix(NEW.valor, 4) BEGIN UPDATE ajustes SET valor = dec_fix(NEW.valor, 4) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_ajustes_valor_u AFTER UPDATE OF valor ON ajustes WHEN NEW.valor IS NOT dec_fix(NEW.valor, 4) BEGIN UPDATE ajustes SET valor = dec_fix(NEW.valor, 4) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_ajustes AFTER UPDATE ON ajustes WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.chave IS NOT OLD.chave OR NEW.label IS NOT OLD.label OR NEW.valor IS NOT OLD.valor OR NEW.confirmado IS NOT OLD.confirmado) BEGIN UPDATE ajustes SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE categorias (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT COLLATE AI_CI NOT NULL CHECK (length(nome) <= 100),
  apelido TEXT COLLATE AI_CI CHECK (length(apelido) <= 24),
  icone TEXT CHECK (length(icone) <= 24),
  grupo TEXT NOT NULL CHECK (grupo IN ('Entradas', 'Fixos', 'Dia a dia', 'Cartao', 'Ano', 'Emprestimo', 'Poupanca', 'Investimento')),
  sinal INTEGER NOT NULL CHECK (sinal IN (-1, 1)),
  operacional INTEGER GENERATED ALWAYS AS (CASE WHEN grupo IN ('Emprestimo', 'Poupanca', 'Investimento') THEN 0 ELSE 1 END) STORED,
  ativo INTEGER NOT NULL DEFAULT 1,
  ordem INTEGER NOT NULL DEFAULT 0,
  essencial INTEGER NOT NULL DEFAULT 0,
  UNIQUE (nome)
) STRICT;

CREATE TABLE cartoes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT COLLATE AI_CI NOT NULL CHECK (length(nome) <= 60),
  dia_fechamento INTEGER NOT NULL,
  dia_vencimento INTEGER NOT NULL,
  limite REAL CHECK (abs(limite) < 1e10),
  alimenta_plano INTEGER NOT NULL DEFAULT 0,
  ativo INTEGER NOT NULL DEFAULT 1,
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em)),
  visual TEXT CHECK (length(visual) <= 24),
  CONSTRAINT chk_cartao_dias CHECK (dia_fechamento BETWEEN 1 AND 31 AND dia_vencimento BETWEEN 1 AND 31),
  CONSTRAINT chk_cartao_limite CHECK (limite IS NULL OR limite > 0)
) STRICT;
CREATE TRIGGER dec_cartoes_limite_i AFTER INSERT ON cartoes WHEN NEW.limite IS NOT dec_fix(NEW.limite, 2) BEGIN UPDATE cartoes SET limite = dec_fix(NEW.limite, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_cartoes_limite_u AFTER UPDATE OF limite ON cartoes WHEN NEW.limite IS NOT dec_fix(NEW.limite, 2) BEGIN UPDATE cartoes SET limite = dec_fix(NEW.limite, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_cartoes AFTER UPDATE ON cartoes WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.dia_fechamento IS NOT OLD.dia_fechamento OR NEW.dia_vencimento IS NOT OLD.dia_vencimento OR NEW.limite IS NOT OLD.limite OR NEW.alimenta_plano IS NOT OLD.alimenta_plano OR NEW.ativo IS NOT OLD.ativo OR NEW.criado_em IS NOT OLD.criado_em OR NEW.visual IS NOT OLD.visual) BEGIN UPDATE cartoes SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE cartao_compras (
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
  CONSTRAINT chk_cc_valor CHECK (valor_total > 0)
) STRICT;
CREATE INDEX idx_cc_cartao_fatura ON cartao_compras (cartao_id, primeira_fatura);
CREATE INDEX cc_categoria ON cartao_compras (categoria_id);
CREATE INDEX cc_usuario ON cartao_compras (usuario_id);
CREATE TRIGGER dec_cartao_compras_valor_total_i AFTER INSERT ON cartao_compras WHEN NEW.valor_total IS NOT dec_fix(NEW.valor_total, 2) BEGIN UPDATE cartao_compras SET valor_total = dec_fix(NEW.valor_total, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_cartao_compras_valor_total_u AFTER UPDATE OF valor_total ON cartao_compras WHEN NEW.valor_total IS NOT dec_fix(NEW.valor_total, 2) BEGIN UPDATE cartao_compras SET valor_total = dec_fix(NEW.valor_total, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_cartao_compras AFTER UPDATE ON cartao_compras WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.cartao_id IS NOT OLD.cartao_id OR NEW.data IS NOT OLD.data OR NEW.descricao IS NOT OLD.descricao OR NEW.categoria_id IS NOT OLD.categoria_id OR NEW.valor_total IS NOT OLD.valor_total OR NEW.parcelas IS NOT OLD.parcelas OR NEW.primeira_fatura IS NOT OLD.primeira_fatura OR NEW.usuario_id IS NOT OLD.usuario_id OR NEW.criado_em IS NOT OLD.criado_em) BEGIN UPDATE cartao_compras SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE contas_recorrentes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  categoria_id INTEGER NOT NULL REFERENCES categorias (id),
  dia_vencimento INTEGER NOT NULL,
  ativo INTEGER NOT NULL DEFAULT 1,
  UNIQUE (categoria_id),
  CONSTRAINT chk_dia CHECK (dia_vencimento BETWEEN 1 AND 31)
) STRICT;

CREATE TABLE lancamentos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  data TEXT NOT NULL CHECK (data IS date(data)),
  descricao TEXT COLLATE AI_CI NOT NULL CHECK (length(descricao) <= 255),
  categoria_id INTEGER NOT NULL REFERENCES categorias (id),
  valor REAL NOT NULL CHECK (abs(valor) < 1e10),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em)),
  CONSTRAINT chk_valor_positivo CHECK (valor > 0)
) STRICT;
CREATE INDEX idx_lanc_usuario ON lancamentos (usuario_id);
CREATE INDEX idx_data ON lancamentos (data);
CREATE INDEX idx_categoria_data ON lancamentos (categoria_id, data);
CREATE TRIGGER dec_lancamentos_valor_i AFTER INSERT ON lancamentos WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE lancamentos SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_lancamentos_valor_u AFTER UPDATE OF valor ON lancamentos WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE lancamentos SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_lancamentos AFTER UPDATE ON lancamentos WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.data IS NOT OLD.data OR NEW.descricao IS NOT OLD.descricao OR NEW.categoria_id IS NOT OLD.categoria_id OR NEW.valor IS NOT OLD.valor OR NEW.usuario_id IS NOT OLD.usuario_id OR NEW.criado_em IS NOT OLD.criado_em) BEGIN UPDATE lancamentos SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE login_tentativas (
  login TEXT COLLATE AI_CI NOT NULL PRIMARY KEY CHECK (length(login) <= 100),
  tentativas INTEGER NOT NULL DEFAULT 0 CHECK (tentativas BETWEEN 0 AND 255),
  bloqueado_ate TEXT CHECK (bloqueado_ate IS datetime(bloqueado_ate)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em))
) STRICT;
CREATE TRIGGER upd_login_tentativas AFTER UPDATE ON login_tentativas WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.login IS NOT OLD.login OR NEW.tentativas IS NOT OLD.tentativas OR NEW.bloqueado_ate IS NOT OLD.bloqueado_ate) BEGIN UPDATE login_tentativas SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE migracoes (
  arquivo TEXT NOT NULL PRIMARY KEY CHECK (length(arquivo) <= 190),
  aplicada_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (aplicada_em IS datetime(aplicada_em))
) STRICT;

CREATE TABLE meses (
  mes TEXT NOT NULL PRIMARY KEY CHECK (mes IS date(mes)),
  situacao TEXT NOT NULL DEFAULT 'PREVISAO' CHECK (situacao IN ('PREVISAO', 'REAL')),
  fechado_em TEXT CHECK (fechado_em IS datetime(fechado_em))
) STRICT;

CREATE TABLE metas (
  categoria_id INTEGER NOT NULL PRIMARY KEY REFERENCES categorias (id) ON DELETE CASCADE,
  valor REAL NOT NULL CHECK (abs(valor) < 1e12),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em))
) STRICT;
CREATE TRIGGER dec_metas_valor_i AFTER INSERT ON metas WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE metas SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_metas_valor_u AFTER UPDATE OF valor ON metas WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE metas SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_metas AFTER UPDATE ON metas WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.categoria_id IS NOT OLD.categoria_id OR NEW.valor IS NOT OLD.valor) BEGIN UPDATE metas SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE previsao (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  categoria_id INTEGER NOT NULL REFERENCES categorias (id),
  mes TEXT NOT NULL REFERENCES meses (mes) CHECK (mes IS date(mes)),
  valor REAL NOT NULL DEFAULT 0 CHECK (abs(valor) < 1e10),
  CONSTRAINT uq_categoria_mes UNIQUE (categoria_id, mes)
) STRICT;
CREATE INDEX idx_previsao_mes ON previsao (mes);
CREATE TRIGGER dec_previsao_valor_i AFTER INSERT ON previsao WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE previsao SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_valor_u AFTER UPDATE OF valor ON previsao WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE previsao SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;

CREATE TABLE previsao_lotes (
  lote TEXT NOT NULL PRIMARY KEY CHECK (length(lote) <= 16),
  origem TEXT NOT NULL CHECK (origem IN ('manual', 'propagar', 'sugestao', 'fechar', 'migracao', 'congelar', 'cartao')),
  mes_inicio TEXT NOT NULL CHECK (mes_inicio IS date(mes_inicio)),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  objetivo_antes REAL CHECK (abs(objetivo_antes) < 1e10),
  objetivo_depois REAL CHECK (abs(objetivo_depois) < 1e10),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  desfeito_em TEXT CHECK (desfeito_em IS datetime(desfeito_em))
) STRICT;
CREATE INDEX idx_lotes_criado ON previsao_lotes (criado_em);
CREATE INDEX fk_lotes_usuario ON previsao_lotes (usuario_id);
CREATE TRIGGER dec_previsao_lotes_objetivo_antes_i AFTER INSERT ON previsao_lotes WHEN NEW.objetivo_antes IS NOT dec_fix(NEW.objetivo_antes, 4) BEGIN UPDATE previsao_lotes SET objetivo_antes = dec_fix(NEW.objetivo_antes, 4) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_lotes_objetivo_antes_u AFTER UPDATE OF objetivo_antes ON previsao_lotes WHEN NEW.objetivo_antes IS NOT dec_fix(NEW.objetivo_antes, 4) BEGIN UPDATE previsao_lotes SET objetivo_antes = dec_fix(NEW.objetivo_antes, 4) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_lotes_objetivo_depois_i AFTER INSERT ON previsao_lotes WHEN NEW.objetivo_depois IS NOT dec_fix(NEW.objetivo_depois, 4) BEGIN UPDATE previsao_lotes SET objetivo_depois = dec_fix(NEW.objetivo_depois, 4) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_lotes_objetivo_depois_u AFTER UPDATE OF objetivo_depois ON previsao_lotes WHEN NEW.objetivo_depois IS NOT dec_fix(NEW.objetivo_depois, 4) BEGIN UPDATE previsao_lotes SET objetivo_depois = dec_fix(NEW.objetivo_depois, 4) WHERE rowid = NEW.rowid; END;

CREATE TABLE previsao_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lote TEXT NOT NULL REFERENCES previsao_lotes (lote) ON DELETE CASCADE,
  categoria_id INTEGER NOT NULL REFERENCES categorias (id),
  mes TEXT NOT NULL REFERENCES meses (mes) CHECK (mes IS date(mes)),
  antes REAL CHECK (abs(antes) < 1e10),
  depois REAL CHECK (abs(depois) < 1e10)
) STRICT;
CREATE INDEX idx_log_lote ON previsao_log (lote);
CREATE INDEX fk_log_categoria ON previsao_log (categoria_id);
CREATE INDEX fk_log_mes ON previsao_log (mes);
CREATE TRIGGER dec_previsao_log_antes_i AFTER INSERT ON previsao_log WHEN NEW.antes IS NOT dec_fix(NEW.antes, 2) BEGIN UPDATE previsao_log SET antes = dec_fix(NEW.antes, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_log_antes_u AFTER UPDATE OF antes ON previsao_log WHEN NEW.antes IS NOT dec_fix(NEW.antes, 2) BEGIN UPDATE previsao_log SET antes = dec_fix(NEW.antes, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_log_depois_i AFTER INSERT ON previsao_log WHEN NEW.depois IS NOT dec_fix(NEW.depois, 2) BEGIN UPDATE previsao_log SET depois = dec_fix(NEW.depois, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_previsao_log_depois_u AFTER UPDATE OF depois ON previsao_log WHEN NEW.depois IS NOT dec_fix(NEW.depois, 2) BEGIN UPDATE previsao_log SET depois = dec_fix(NEW.depois, 2) WHERE rowid = NEW.rowid; END;

CREATE TABLE saldo_conferencias (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  data TEXT NOT NULL CHECK (data IS date(data)),
  saldo_informado REAL NOT NULL CHECK (abs(saldo_informado) < 1e10),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em))
) STRICT;
CREATE INDEX idx_saldo_usuario ON saldo_conferencias (usuario_id);
CREATE TRIGGER dec_saldo_conferencias_saldo_informado_i AFTER INSERT ON saldo_conferencias WHEN NEW.saldo_informado IS NOT dec_fix(NEW.saldo_informado, 2) BEGIN UPDATE saldo_conferencias SET saldo_informado = dec_fix(NEW.saldo_informado, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_saldo_conferencias_saldo_informado_u AFTER UPDATE OF saldo_informado ON saldo_conferencias WHEN NEW.saldo_informado IS NOT dec_fix(NEW.saldo_informado, 2) BEGIN UPDATE saldo_conferencias SET saldo_informado = dec_fix(NEW.saldo_informado, 2) WHERE rowid = NEW.rowid; END;

CREATE TABLE sessoes_lembradas (
  seletor TEXT NOT NULL PRIMARY KEY CHECK (length(seletor) <= 24),
  validador_hash TEXT NOT NULL CHECK (length(validador_hash) <= 64),
  validador_ant_hash TEXT CHECK (length(validador_ant_hash) <= 64),
  rotacionado_em TEXT CHECK (rotacionado_em IS datetime(rotacionado_em)),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
  expira_em TEXT NOT NULL CHECK (expira_em IS datetime(expira_em)),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  ultimo_uso TEXT CHECK (ultimo_uso IS datetime(ultimo_uso))
) STRICT;
CREATE INDEX idx_lembradas_usuario ON sessoes_lembradas (usuario_id);

CREATE TABLE duas_etapas_recuperacao (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
  codigo_hash TEXT NOT NULL CHECK (length(codigo_hash) <= 255),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em))
) STRICT;
CREATE INDEX idx_recuperacao_usuario ON duas_etapas_recuperacao (usuario_id);

CREATE TABLE wishlist_itens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT COLLATE AI_CI NOT NULL CHECK (length(nome) <= 160),
  url TEXT CHECK (length(url) <= 2048),
  valor REAL CHECK (abs(valor) < 1e12),
  moeda TEXT NOT NULL DEFAULT 'BRL' CHECK (length(moeda) <= 3),
  posicao INTEGER NOT NULL DEFAULT 0 CHECK (posicao >= 0),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  comprado_em TEXT CHECK (comprado_em IS date(comprado_em)),
  excluido_em TEXT CHECK (excluido_em IS datetime(excluido_em)),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em)),
  CONSTRAINT chk_wishlist_valor CHECK (valor IS NULL OR valor > 0)
) STRICT;
CREATE INDEX idx_wishlist_ordem ON wishlist_itens (excluido_em, comprado_em, posicao);
CREATE INDEX fk_wishlist_usuario ON wishlist_itens (usuario_id);
CREATE TRIGGER dec_wishlist_itens_valor_i AFTER INSERT ON wishlist_itens WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE wishlist_itens SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_wishlist_itens_valor_u AFTER UPDATE OF valor ON wishlist_itens WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE wishlist_itens SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER upd_wishlist_itens AFTER UPDATE ON wishlist_itens WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome OR NEW.url IS NOT OLD.url OR NEW.valor IS NOT OLD.valor OR NEW.moeda IS NOT OLD.moeda OR NEW.posicao IS NOT OLD.posicao OR NEW.usuario_id IS NOT OLD.usuario_id OR NEW.comprado_em IS NOT OLD.comprado_em OR NEW.excluido_em IS NOT OLD.excluido_em OR NEW.criado_em IS NOT OLD.criado_em) BEGIN UPDATE wishlist_itens SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE TABLE wishlist_fotos (
  item_id INTEGER NOT NULL PRIMARY KEY REFERENCES wishlist_itens (id) ON DELETE CASCADE,
  tipo TEXT NOT NULL CHECK (length(tipo) <= 32),
  largura INTEGER NOT NULL CHECK (largura >= 0),
  altura INTEGER NOT NULL CHECK (altura >= 0),
  hash TEXT NOT NULL CHECK (length(hash) <= 40),
  dados BLOB NOT NULL
) STRICT;

CREATE TABLE cotacoes (
  moeda TEXT NOT NULL PRIMARY KEY CHECK (length(moeda) <= 3),
  valor REAL NOT NULL CHECK (abs(valor) < 1e10),
  data TEXT NOT NULL CHECK (data IS date(data)),
  buscado_em TEXT NOT NULL CHECK (buscado_em IS datetime(buscado_em))
) STRICT;
CREATE TRIGGER dec_cotacoes_valor_i AFTER INSERT ON cotacoes WHEN NEW.valor IS NOT dec_fix(NEW.valor, 8) BEGIN UPDATE cotacoes SET valor = dec_fix(NEW.valor, 8) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_cotacoes_valor_u AFTER UPDATE OF valor ON cotacoes WHEN NEW.valor IS NOT dec_fix(NEW.valor, 8) BEGIN UPDATE cotacoes SET valor = dec_fix(NEW.valor, 8) WHERE rowid = NEW.rowid; END;

CREATE TABLE plano_fotos (
  mes TEXT NOT NULL PRIMARY KEY CHECK (mes IS date(mes)),
  criada_em TEXT NOT NULL CHECK (criada_em IS date(criada_em)),
  versao INTEGER NOT NULL DEFAULT 1,
  dados TEXT NOT NULL
) STRICT;

CREATE TABLE site_icone (
  id INTEGER NOT NULL PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  tipo TEXT NOT NULL CHECK (length(tipo) <= 32),
  hash TEXT NOT NULL CHECK (length(hash) <= 40),
  dados BLOB NOT NULL,
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em))
) STRICT;
CREATE TRIGGER upd_site_icone AFTER UPDATE ON site_icone WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.id IS NOT OLD.id OR NEW.tipo IS NOT OLD.tipo OR NEW.hash IS NOT OLD.hash OR NEW.dados IS NOT OLD.dados) BEGIN UPDATE site_icone SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END;

CREATE VIEW previsao_efetiva AS
SELECT c.id AS categoria_id, m.mes AS mes,
       CAST(COALESCE(p.valor, CASE WHEN m.situacao = 'PREVISAO' AND c.ativo = 1 AND c.operacional = 1 AND c.sinal = -1 THEN mt.valor END, 0) AS REAL) AS valor,
       CASE WHEN p.id IS NOT NULL THEN 'mes'
            WHEN mt.valor IS NOT NULL AND m.situacao = 'PREVISAO' AND c.ativo = 1 AND c.operacional = 1 AND c.sinal = -1 THEN 'meta' END AS fonte
FROM categorias c
CROSS JOIN meses m
LEFT JOIN previsao p ON p.categoria_id = c.id AND p.mes = m.mes
LEFT JOIN metas mt ON mt.categoria_id = c.id;
