-- Verificação em duas etapas: o segredo do app autenticador, cifrado, e o último passo de 30 s aceito.
-- Os códigos de recuperação ficam só como hash, um por linha, e a linha some quando o código é usado.
ALTER TABLE usuarios ADD COLUMN duas_etapas_segredo TEXT CHECK (length(duas_etapas_segredo) <= 255);
ALTER TABLE usuarios ADD COLUMN duas_etapas_passo INTEGER CHECK (duas_etapas_passo >= 0);
CREATE TABLE duas_etapas_recuperacao (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
  codigo_hash TEXT NOT NULL CHECK (length(codigo_hash) <= 255),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em))
) STRICT;
CREATE INDEX idx_recuperacao_usuario ON duas_etapas_recuperacao (usuario_id);
