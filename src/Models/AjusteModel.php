<?php
namespace Financeiro\Models;

use Financeiro\Database;

final class AjusteModel
{
    /** Chaves que a tela de Ajustes pode gravar (SPEC §2.8). `objetivo` só muda pela sugestão. */
    public const EDITAVEIS = [
        'saldo_ini', 'poupanca_ini', 'recebido_emprestimo', 'parcela_mensal',
        'n_parcelas', 'juros_mes', 'consignado_inicio', 'reserva_minima', 'meta_final',
    ];

    public static function valor(string $chave, float $default = 0.0): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT valor FROM ajustes WHERE chave = :chave');
        $stmt->execute(['chave' => $chave]);
        $row = $stmt->fetch();
        return $row ? (float) $row['valor'] : $default;
    }

    public static function confirmado(string $chave): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT confirmado FROM ajustes WHERE chave = :chave');
        $stmt->execute(['chave' => $chave]);
        $row = $stmt->fetch();
        return $row && (bool) $row['confirmado'];
    }

    /** @return array<string,float> chave => valor, numa consulta só (usado pelo motor). */
    public static function mapa(): array
    {
        $pdo = Database::connection();
        $out = [];
        foreach ($pdo->query('SELECT chave, valor FROM ajustes') as $row) {
            $out[$row['chave']] = (float) $row['valor'];
        }
        return $out;
    }

    /** @return array<int,array> todos os ajustes, na ordem de exibição da tela */
    public static function todos(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT chave, label, valor, confirmado FROM ajustes ORDER BY
            FIELD(chave, "saldo_ini","poupanca_ini","recebido_emprestimo","parcela_mensal","n_parcelas","juros_mes","consignado_inicio","reserva_minima","meta_final","objetivo")');
        return $stmt->fetchAll();
    }

    /** Grava valor e confirmado juntos (assinatura antiga, mantida). */
    public static function set(string $chave, float $valor, bool $confirmado): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE ajustes SET valor = :valor, confirmado = :confirmado WHERE chave = :chave'
        );
        $stmt->execute([
            'valor'      => $valor,
            'confirmado' => $confirmado ? 1 : 0,
            'chave'      => $chave,
        ]);
    }

    /**
     * Grava só o valor, preservando `confirmado`. Se a chave ainda não existir (instalação
     * antiga sem a migração), cria a linha com label = chave e confirmado = 0.
     */
    public static function setValor(string $chave, float $valor): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO ajustes (chave, label, valor, confirmado) VALUES (:chave, :label, :valor, 0)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $stmt->execute(['chave' => $chave, 'label' => $chave, 'valor' => $valor]);
    }

    /** Grava só `confirmado`, preservando o valor. */
    public static function setConfirmado(string $chave, bool $c): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE ajustes SET confirmado = :c WHERE chave = :chave');
        $stmt->execute(['c' => $c ? 1 : 0, 'chave' => $chave]);
    }

    /** Saldo devedor do empréstimo hoje, via fórmula de valor presente (PRICE). */
    public static function devedorHoje(): float
    {
        return self::devedorPor(self::valor('parcela_mensal'), self::valor('n_parcelas'), self::valor('juros_mes'));
    }

    /** Mesma fórmula de devedorHoje(), a partir de valores já carregados (sem consulta). */
    public static function devedorPor(float $parcela, float $n, float $juros): float
    {
        if ($juros <= 0 || $n <= 0) {
            return $parcela * $n;
        }
        return $parcela * (1 - (1 + $juros) ** -$n) / $juros;
    }

    /**
     * Taxa mensal implícita no crédito recebido: r tal que PV(r, n_parcelas, parcela_mensal)
     * = recebido_emprestimo (bisseção). Hoje: 0,019703… (1,97%). null sem dados ou sem solução.
     */
    public static function taxaPeloCredito(): ?float
    {
        $pv      = self::valor('recebido_emprestimo');
        $parcela = self::valor('parcela_mensal');
        $n       = self::valor('n_parcelas');
        if ($pv <= 0 || $parcela <= 0 || $n <= 0 || $parcela * $n <= $pv) {
            return null; // sem juros positivos possíveis
        }
        $f = static fn(float $r): float => $parcela * (1 - (1 + $r) ** -$n) / $r - $pv; // decrescente em r
        $lo = 1e-9;
        $hi = 1.0;
        if ($f($hi) > 0) {
            return null;
        }
        for ($i = 0; $i < 100; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($f($mid) > 0) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return ($lo + $hi) / 2;
    }
}
