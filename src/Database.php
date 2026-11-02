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
}
