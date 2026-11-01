-- Aba Wishlist: o que o casal quer comprar, em ordem, com link, valor e foto.
-- A foto fica no banco para entrar nos backups e não depender de arquivos no site.
CREATE TABLE IF NOT EXISTS wishlist_itens (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(160)  NOT NULL,
    url          VARCHAR(2048) NULL,
    valor        DECIMAL(14,2) NULL,
    moeda        CHAR(3)       NOT NULL DEFAULT 'BRL',
    posicao      INT UNSIGNED  NOT NULL DEFAULT 0,
    usuario_id   INT UNSIGNED  NOT NULL,
    comprado_em  DATE          NULL,
    excluido_em  DATETIME      NULL,
    criado_em    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wishlist_ordem (excluido_em, comprado_em, posicao),
    CONSTRAINT fk_wishlist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT chk_wishlist_valor CHECK (valor IS NULL OR valor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cotacoes (
    moeda      CHAR(3)       NOT NULL PRIMARY KEY,
    valor      DECIMAL(18,8) NOT NULL,
    data       DATE          NOT NULL,
    buscado_em DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS wishlist_fotos (
    item_id  INT UNSIGNED NOT NULL PRIMARY KEY,
    tipo     VARCHAR(32)  NOT NULL,
    largura  INT UNSIGNED NOT NULL,
    altura   INT UNSIGNED NOT NULL,
    hash     CHAR(40)     NOT NULL,
    dados    MEDIUMBLOB   NOT NULL,
    CONSTRAINT fk_wishlist_foto_item FOREIGN KEY (item_id) REFERENCES wishlist_itens (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
