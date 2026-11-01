-- O ícone do site, escolhido em Ajustes. Sem linha aqui, vale a imagem padrão.
CREATE TABLE IF NOT EXISTS site_icone (
  id tinyint unsigned NOT NULL DEFAULT 1,
  tipo varchar(32) NOT NULL,
  hash char(40) NOT NULL,
  dados mediumblob NOT NULL,
  atualizado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_site_icone_unico CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
