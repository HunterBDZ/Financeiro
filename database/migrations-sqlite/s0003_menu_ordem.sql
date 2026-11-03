-- Ordem das abas do menu, por conta: JSON com as chaves das abas (Financeiro\Menu). NULL = a ordem padrão.
ALTER TABLE usuarios ADD COLUMN menu_ordem TEXT CHECK (length(menu_ordem) <= 1000);
