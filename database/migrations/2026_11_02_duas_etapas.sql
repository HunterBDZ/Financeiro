-- Verificação em duas etapas: o segredo do app autenticador, cifrado, e o último passo de 30 s aceito.
-- Os códigos de recuperação ficam só como hash, um por linha, e a linha some quando o código é usado.
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'duas_etapas_segredo');
SET @sql := IF(@tem = 0, 'ALTER TABLE usuarios ADD COLUMN duas_etapas_segredo VARCHAR(255) NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'duas_etapas_passo');
SET @sql := IF(@tem = 0, 'ALTER TABLE usuarios ADD COLUMN duas_etapas_passo INT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS duas_etapas_recuperacao (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    codigo_hash VARCHAR(255) NOT NULL,
    criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_recuperacao_usuario (usuario_id),
    CONSTRAINT fk_recuperacao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
