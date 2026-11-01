-- A memória do plano: o que ele previa no começo de cada mês, para o relatório comparar depois.
CREATE TABLE IF NOT EXISTS plano_fotos (
  mes date NOT NULL,
  criada_em date NOT NULL,
  versao tinyint unsigned NOT NULL DEFAULT 1,
  dados mediumtext NOT NULL,
  PRIMARY KEY (mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
