-- Cartão de crédito (2026-10-16): o cartão (fechamento, vencimento, limite) e as compras, à vista ou
-- parceladas. As parcelas não têm tabela: saem de valor_total, parcelas e primeira_fatura (o mês em que
-- vence a fatura da 1ª parcela). Com alimenta_plano = 1, a soma das parcelas de cada fatura vira a
-- previsão da categoria "Fatura do cartão" no mês em que ela vence (lote de previsão de origem 'cartao').
-- As compras NÃO contam nas categorias: no plano só a fatura conta (regime de caixa).

CREATE TABLE IF NOT EXISTS cartoes (
  id int unsigned NOT NULL AUTO_INCREMENT,
  nome varchar(60) NOT NULL,
  dia_fechamento tinyint unsigned NOT NULL,
  dia_vencimento tinyint unsigned NOT NULL,
  limite decimal(12,2) DEFAULT NULL,
  alimenta_plano tinyint(1) NOT NULL DEFAULT 0,
  ativo tinyint(1) NOT NULL DEFAULT 1,
  criado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_cartao_dias CHECK (dia_fechamento BETWEEN 1 AND 31 AND dia_vencimento BETWEEN 1 AND 31),
  CONSTRAINT chk_cartao_limite CHECK (limite IS NULL OR limite > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cartao_compras (
  id int unsigned NOT NULL AUTO_INCREMENT,
  cartao_id int unsigned NOT NULL,
  data date NOT NULL,
  descricao varchar(255) NOT NULL,
  categoria_id int unsigned DEFAULT NULL,
  valor_total decimal(12,2) NOT NULL,
  parcelas tinyint unsigned NOT NULL DEFAULT 1,
  primeira_fatura date NOT NULL,
  usuario_id int unsigned NOT NULL,
  criado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cc_cartao_fatura (cartao_id, primeira_fatura),
  KEY cc_categoria (categoria_id),
  KEY cc_usuario (usuario_id),
  CONSTRAINT cc_fk_cartao FOREIGN KEY (cartao_id) REFERENCES cartoes (id),
  CONSTRAINT cc_fk_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
  CONSTRAINT cc_fk_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT chk_cc_valor CHECK (valor_total > 0),
  CONSTRAINT chk_cc_parcelas CHECK (parcelas BETWEEN 1 AND 48),
  CONSTRAINT chk_cc_primeira CHECK (DAY(primeira_fatura) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE previsao_lotes
  MODIFY origem enum('manual','propagar','sugestao','fechar','migracao','congelar','cartao') NOT NULL;
