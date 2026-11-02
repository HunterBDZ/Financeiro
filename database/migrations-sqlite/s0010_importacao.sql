-- 2.0, segunda parte: importar extrato (OFX ou CSV) com revisão.
-- importacoes: cada lote gravado, com o destino (uma conta ou um cartão), o arquivo (nome e SHA-256, sem o conteúdo) e
-- quantas linhas entraram; "Desfazer importação" apaga as linhas do lote e marca desfeita_em.
-- Cada lançamento e cada compra no cartão importados levam importacao_id e importacao_chave: a identidade da linha no
-- arquivo (destino, data, valor, descrição, FITID e ordem da ocorrência), que acha a mesma linha num arquivo seguinte.
-- importacao_regras: "descrição contém → categoria". importacao_layouts: as colunas de um CSV, pelo hash do cabeçalho.
-- As colunas novas entram no fim, como no schema.sqlite.sql.

CREATE TABLE importacoes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  conta_id INTEGER REFERENCES contas (id),
  cartao_id INTEGER REFERENCES cartoes (id),
  nome TEXT NOT NULL CHECK (length(nome) <= 255),
  formato TEXT NOT NULL CHECK (formato IN ('ofx', 'csv')),
  hash TEXT NOT NULL CHECK (length(hash) <= 64),
  linhas INTEGER NOT NULL DEFAULT 0 CHECK (linhas >= 0),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  desfeita_em TEXT CHECK (desfeita_em IS datetime(desfeita_em)),
  CONSTRAINT chk_imp_destino CHECK ((conta_id IS NULL) <> (cartao_id IS NULL))
) STRICT;
CREATE INDEX idx_importacoes_criado ON importacoes (criado_em);
CREATE INDEX fk_importacoes_conta ON importacoes (conta_id);
CREATE INDEX fk_importacoes_cartao ON importacoes (cartao_id);
CREATE INDEX fk_importacoes_usuario ON importacoes (usuario_id);

CREATE TABLE importacao_regras (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  contem TEXT COLLATE AI_CI NOT NULL CHECK (length(contem) BETWEEN 2 AND 60),
  categoria_id INTEGER NOT NULL REFERENCES categorias (id) ON DELETE CASCADE,
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  UNIQUE (contem)
) STRICT;
CREATE INDEX fk_imp_regras_categoria ON importacao_regras (categoria_id);
CREATE INDEX fk_imp_regras_usuario ON importacao_regras (usuario_id);

CREATE TABLE importacao_layouts (
  hash TEXT NOT NULL PRIMARY KEY CHECK (length(hash) <= 64),
  mapa TEXT NOT NULL CHECK (length(mapa) <= 2000),
  atualizado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (atualizado_em IS datetime(atualizado_em))
) STRICT;

ALTER TABLE lancamentos ADD COLUMN importacao_id INTEGER REFERENCES importacoes (id);
ALTER TABLE lancamentos ADD COLUMN importacao_chave TEXT CHECK (length(importacao_chave) <= 64);
CREATE INDEX idx_lanc_importacao ON lancamentos (importacao_id);
CREATE INDEX idx_lanc_importacao_chave ON lancamentos (importacao_chave);

ALTER TABLE cartao_compras ADD COLUMN importacao_id INTEGER REFERENCES importacoes (id);
ALTER TABLE cartao_compras ADD COLUMN importacao_chave TEXT CHECK (length(importacao_chave) <= 64);
CREATE INDEX idx_cc_importacao ON cartao_compras (importacao_id);
CREATE INDEX idx_cc_importacao_chave ON cartao_compras (importacao_chave);
