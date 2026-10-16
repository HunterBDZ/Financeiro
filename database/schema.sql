
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ajustes` (
  `chave` varchar(50) NOT NULL,
  `label` varchar(255) NOT NULL,
  `valor` decimal(14,4) NOT NULL,
  `confirmado` tinyint(1) NOT NULL DEFAULT '0',
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cartao_compras` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cartao_id` int unsigned NOT NULL,
  `data` date NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `categoria_id` int unsigned DEFAULT NULL,
  `valor_total` decimal(12,2) NOT NULL,
  `parcelas` tinyint unsigned NOT NULL DEFAULT '1',
  `primeira_fatura` date NOT NULL,
  `usuario_id` int unsigned NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cc_cartao_fatura` (`cartao_id`,`primeira_fatura`),
  KEY `cc_categoria` (`categoria_id`),
  KEY `cc_usuario` (`usuario_id`),
  CONSTRAINT `cc_fk_cartao` FOREIGN KEY (`cartao_id`) REFERENCES `cartoes` (`id`),
  CONSTRAINT `cc_fk_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `cc_fk_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `chk_cc_parcelas` CHECK ((`parcelas` between 1 and 48)),
  CONSTRAINT `chk_cc_primeira` CHECK ((dayofmonth(`primeira_fatura`) = 1)),
  CONSTRAINT `chk_cc_valor` CHECK ((`valor_total` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cartao_compras_v1` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `descricao` varchar(255) NOT NULL,
  `valor_parcela` decimal(12,2) NOT NULL,
  `n_parcelas` smallint unsigned NOT NULL,
  `data_primeira_parcela` date NOT NULL,
  `usuario_id` int unsigned NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `cartao_compras_v1_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cartoes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(60) NOT NULL,
  `dia_fechamento` tinyint unsigned NOT NULL,
  `dia_vencimento` tinyint unsigned NOT NULL,
  `limite` decimal(12,2) DEFAULT NULL,
  `alimenta_plano` tinyint(1) NOT NULL DEFAULT '0',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_cartao_dias` CHECK (((`dia_fechamento` between 1 and 31) and (`dia_vencimento` between 1 and 31))),
  CONSTRAINT `chk_cartao_limite` CHECK (((`limite` is null) or (`limite` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `apelido` varchar(24) DEFAULT NULL,
  `icone` varchar(24) DEFAULT NULL,
  `grupo` enum('Entradas','Fixos','Dia a dia','Cartao','Ano','Emprestimo','Poupanca') NOT NULL,
  `sinal` tinyint NOT NULL,
  `operacional` tinyint(1) GENERATED ALWAYS AS ((case when (`grupo` in (_utf8mb4'Emprestimo',_utf8mb4'Poupanca')) then 0 else 1 end)) STORED,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `ordem` smallint NOT NULL DEFAULT '0',
  `essencial` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`),
  CONSTRAINT `chk_sinal` CHECK ((`sinal` in (-(1),1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contas_recorrentes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `categoria_id` int unsigned NOT NULL,
  `dia_vencimento` tinyint unsigned NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `categoria_id` (`categoria_id`),
  CONSTRAINT `contas_recorrentes_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `chk_dia` CHECK ((`dia_vencimento` between 1 and 31))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lancamentos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `data` date NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `categoria_id` int unsigned NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `usuario_id` int unsigned NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_data` (`data`),
  KEY `idx_categoria_data` (`categoria_id`,`data`),
  CONSTRAINT `lancamentos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `lancamentos_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `chk_valor_positivo` CHECK ((`valor` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_tentativas` (
  `login` varchar(100) NOT NULL,
  `tentativas` tinyint unsigned NOT NULL DEFAULT '0',
  `bloqueado_ate` timestamp NULL DEFAULT NULL,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meses` (
  `mes` date NOT NULL,
  `situacao` enum('PREVISAO','REAL') NOT NULL DEFAULT 'PREVISAO',
  `fechado_em` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `metas` (
  `categoria_id` int unsigned NOT NULL,
  `valor` decimal(14,2) NOT NULL,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`categoria_id`),
  CONSTRAINT `fk_metas_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `previsao` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `categoria_id` int unsigned NOT NULL,
  `mes` date NOT NULL,
  `valor` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categoria_mes` (`categoria_id`,`mes`),
  KEY `mes` (`mes`),
  CONSTRAINT `previsao_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `previsao_ibfk_2` FOREIGN KEY (`mes`) REFERENCES `meses` (`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `previsao_efetiva` AS SELECT 
 1 AS `categoria_id`,
 1 AS `mes`,
 1 AS `valor`,
 1 AS `fonte`*/;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `previsao_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `lote` char(16) NOT NULL,
  `categoria_id` int unsigned NOT NULL,
  `mes` date NOT NULL,
  `antes` decimal(12,2) DEFAULT NULL,
  `depois` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_log_lote` (`lote`),
  KEY `fk_log_categoria` (`categoria_id`),
  KEY `fk_log_mes` (`mes`),
  CONSTRAINT `fk_log_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `fk_log_lote` FOREIGN KEY (`lote`) REFERENCES `previsao_lotes` (`lote`) ON DELETE CASCADE,
  CONSTRAINT `fk_log_mes` FOREIGN KEY (`mes`) REFERENCES `meses` (`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `previsao_lotes` (
  `lote` char(16) NOT NULL,
  `origem` enum('manual','propagar','sugestao','fechar','migracao','congelar','cartao') NOT NULL,
  `mes_inicio` date NOT NULL,
  `usuario_id` int unsigned NOT NULL,
  `objetivo_antes` decimal(14,4) DEFAULT NULL,
  `objetivo_depois` decimal(14,4) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `desfeito_em` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`lote`),
  KEY `idx_lotes_criado` (`criado_em`),
  KEY `fk_lotes_usuario` (`usuario_id`),
  CONSTRAINT `fk_lotes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saldo_conferencias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `data` date NOT NULL,
  `saldo_informado` decimal(12,2) NOT NULL,
  `usuario_id` int unsigned NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `saldo_conferencias_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessoes_lembradas` (
  `seletor` char(24) NOT NULL,
  `validador_hash` char(64) NOT NULL,
  `validador_ant_hash` char(64) DEFAULT NULL,
  `rotacionado_em` datetime DEFAULT NULL,
  `usuario_id` int unsigned NOT NULL,
  `expira_em` datetime NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_uso` datetime DEFAULT NULL,
  PRIMARY KEY (`seletor`),
  KEY `idx_lembradas_usuario` (`usuario_id`),
  CONSTRAINT `fk_lembradas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `login` varchar(100) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  `sessao_versao` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `previsao_efetiva`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY INVOKER */
/*!50001 VIEW `previsao_efetiva` AS select `c`.`id` AS `categoria_id`,`m`.`mes` AS `mes`,cast(coalesce(`p`.`valor`,(case when ((`m`.`situacao` = 'PREVISAO') and (`c`.`ativo` = 1) and (`c`.`operacional` = 1) and (`c`.`sinal` = -(1))) then `mt`.`valor` end),0) as decimal(12,2)) AS `valor`,(case when (`p`.`id` is not null) then 'mes' when ((`mt`.`valor` is not null) and (`m`.`situacao` = 'PREVISAO') and (`c`.`ativo` = 1) and (`c`.`operacional` = 1) and (`c`.`sinal` = -(1))) then 'meta' end) AS `fonte` from (((`categorias` `c` join `meses` `m`) left join `previsao` `p` on(((`p`.`categoria_id` = `c`.`id`) and (`p`.`mes` = `m`.`mes`)))) left join `metas` `mt` on((`mt`.`categoria_id` = `c`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

