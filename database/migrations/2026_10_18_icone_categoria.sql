-- 2026-10-15/18 — ícone de cada categoria (pixel art; ver src/Icones.php).
-- Guarda a chave escolhida (lista em src/icones_lista.php). NULL = o app sugere pelo nome e
-- grupo (Icones::sugerir), então as categorias que já existem não precisam de dado novo.
ALTER TABLE categorias ADD COLUMN icone VARCHAR(24) NULL DEFAULT NULL AFTER apelido;
