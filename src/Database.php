<?php
namespace Financeiro;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            if (Sql::sqlite()) {
                return self::conectarSqlite();
            }
            $host = Config::get('DB_HOST', 'localhost');
            $name = Config::get('DB_NAME', 'financeiro');
            $user = Config::get('DB_USER', 'financeiro_app');
            $pass = Config::get('DB_PASS', '');

            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('Falha de conexão com o banco: ' . $e->getMessage());
                http_response_code(500);
                die('Erro interno. Tente novamente em instantes.');
            }
            Site::carregar(self::$instance);   // moeda e fuso; ajusta o relógio do PHP e o desta conexão
        }

        return self::$instance;
    }

    /** Solta o banco: quem vai trocar o arquivo não pode estar com ele aberto. */
    public static function fechar(): void
    {
        self::$instance = null;
    }

    private static function conectarSqlite(): PDO
    {
        try {
            // nunca cria um banco vazio por engano: quem cria é a primeira execução ou o importador
            if (!is_file(Paths::banco())) {
                throw new PDOException('arquivo não encontrado');
            }
            self::$instance = new SqliteConexao(Paths::banco());
        } catch (PDOException $e) {
            error_log('Falha ao abrir o banco: ' . $e->getMessage());
            http_response_code(500);
            die('Erro interno. Tente novamente em instantes.');
        }
        Site::carregar(self::$instance);
        return self::$instance;
    }
}
