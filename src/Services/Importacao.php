<?php
namespace Financeiro\Services;

use Financeiro\Colacao;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ImportacaoModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;

/**
 * A revisão de um extrato lido (ExtratoArquivo) antes de gravar, e o lote gravado.
 *
 * Destino: uma conta (cada linha vira um lançamento: + entrada, − gasto) ou um cartão. No cartão, as compras têm o
 * sinal da maioria das linhas; o sinal contrário é pagamento da fatura (pela descrição), que fica de fora, ou estorno.
 * "LOJA 03/10" vira compra em 10 parcelas, a 1ª duas faturas antes da fatura da data (compra antiga), com o total de
 * 10 parcelas e a descrição sem o "03/10".
 *
 * Identidade de uma linha: destino, data, valor, descrição normalizada e FITID, mais a ordem da ocorrência no arquivo
 * (a 2ª linha igual é a 2ª compra igual, não uma cópia; um banco que repete o FITID não faz perder linha). A parcela
 * troca data e FITID por 1ª fatura, nº de parcelas e valor, que se repetem na fatura seguinte. A identidade
 * já gravada marca a linha como "já importada"; uma linha de mesma data, valor e conta (ou, no cartão, a mesma compra)
 * lançada de outro jeito, com a descrição parecida, como "parece já lançada". As duas vêm desmarcadas. Com a descrição
 * bem diferente, a linha vem marcada, só com a nota "Mesmo valor e data de …"; o valor fora do padrão da coluna do CSV
 * vem desmarcado, com "Confira o valor".
 *
 * Categoria sugerida: a coluna de categoria do CSV, se tiver; senão a regra "contém" de texto mais longo; senão a
 * categoria mais usada (e, no empate, a mais recente) para a mesma descrição normalizada. Sempre do tipo certo:
 * gasto para o que saiu, entrada para o que entrou, gasto do dia a dia (sem a fatura) para o cartão.
 */
final class Importacao
{
    /** A memória olha até três anos para trás. */
    private const MEMORIA_ANOS = 3;

    /** @var array<string, array<int, array>> tipo de linha => categorias que cabem nele */
    private static array $permitidas = [];

    /** Minúsculas, sem acento, sem número e sem pontuação: "Mercado São João 123" → "mercado sao joao". */
    public static function normalizar(string $s): string
    {
        return trim((string) preg_replace('/[^a-z]+/', ' ', Colacao::dobrar($s)));
    }

    /**
     * A parcela no fim da descrição ("LOJA 03/10", "LOJA PARC 3/10", "LOJA PARCELA 3 DE 10"): k, n e a descrição sem ela.
     * @return ?array{k:int, n:int, base:string}
     */
    public static function parcela(string $descricao, ?string $data = null): ?array
    {
        if (!preg_match('/^(.*?)[\s\-]*(?:\bparc(?:ela)?\.?\s*)?(\d{1,2})\s*(?:\/|\bde\b)\s*(\d{1,2})\s*$/iu', $descricao, $m)) {
            return null;
        }
        [$k, $n] = [(int) $m[2], (int) $m[3]];
        if ($n < 2 || $n > 48 || $k < 1 || $k > $n) {
            return null;
        }
        // "MERCADO 05/11" numa linha de 5 de novembro é a data, não a parcela
        if ($data !== null && $k === (int) substr($data, 8, 2) && $n === (int) substr($data, 5, 2)) {
            return null;
        }
        $base = trim((string) $m[1], " \t-");
        return ['k' => $k, 'n' => $n, 'base' => $base !== '' ? $base : $descricao];
    }

    /** Pagamento da fatura, pela descrição. */
    private static function ehPagamento(string $norm): bool
    {
        return (bool) preg_match('/\b(pagamento|pagto|pgto|pag fatura|pagamento fatura|payment|pago|pagamos)\b/', $norm);
    }

    /**
     * A revisão: cada linha com tipo, fatura, identidade, categoria sugerida, aviso de repetida e se pode entrar.
     * $destino = ['tipo' => 'conta'|'cartao', 'id' => int] (+ fecha/vence do cartão); $escolhas = i => ['m' => 0|1,
     * 'c' => categoria] do que a pessoa já mudou nesta revisão.
     * @param list<array{data:string, valor:float, descricao:string, fitid:string, categoria:string}> $linhas
     * @return list<array>
     */
    public static function revisao(array $linhas, array $destino, string $formato, array $escolhas = []): array
    {
        $cartao = $destino['tipo'] === 'cartao' ? CartaoModel::achar((int) $destino['id']) : null;
        $sinalCompra = 1;
        if ($cartao !== null) {
            $neg = count(array_filter($linhas, static fn(array $l): bool => $l['valor'] < 0));
            $pos = count($linhas) - $neg;
            $sinalCompra = $neg > $pos || ($neg === $pos && $formato === 'ofx') ? -1 : 1;
        }
        $ultimo = MesModel::ultimo();
        $fimPlano = $ultimo !== null ? date('Y-m-t', strtotime($ultimo)) : '0000-00-00';

        $out = [];
        $vistas = [];
        foreach ($linhas as $i => $l) {
            $desc = ExtratoArquivo::limpar($l['descricao']);
            $r = ['i' => $i, 'data' => $l['data'], 'valor' => (float) $l['valor'], 'descricao' => $desc, 'fitid' => (string) $l['fitid'],
                  'categoria_csv' => (string) ($l['categoria'] ?? ''), 'k' => 0, 'n' => 1, 'fatura' => null, 'primeira' => null,
                  'gravar' => $desc, 'total' => abs((float) $l['valor']), 'motivo' => null, 'dup' => null, 'parecida' => null,
                  'duvida' => !empty($l['duvida']), 'mesmo' => null];
            if ($cartao !== null) {
                $v = $r['valor'] * $sinalCompra;
                $r['fatura'] = CartaoFatura::faturaDe($l['data'], $cartao['fecha'], $cartao['vence']);
                $r['primeira'] = $r['fatura'];
                $p = $v > 0 ? self::parcela($desc, $l['data']) : null;
                if ($p !== null) {
                    $r['tipo'] = 'parcela';
                    [$r['k'], $r['n'], $r['gravar']] = [$p['k'], $p['n'], $p['base']];
                    $r['primeira'] = CartaoFatura::somaMeses($r['fatura'], -($p['k'] - 1));
                    $r['total'] = round(abs($r['valor']) * $p['n'], 2);
                } elseif ($v > 0) {
                    $r['tipo'] = 'compra';
                } else {
                    $r['tipo'] = self::ehPagamento(self::normalizar($desc)) ? 'pagamento' : 'estorno';
                }
                $identidade = $r['tipo'] === 'parcela'
                    ? implode('|', ['cartao', $cartao['id'], 'parcela', $r['primeira'], $r['n'], number_format(abs($r['valor']), 2, '.', ''), self::normalizar($r['gravar'])])
                    : implode('|', ['cartao', $cartao['id'], $l['data'], number_format($v, 2, '.', ''), self::normalizar($desc), $r['fitid']]);
                if ($r['tipo'] === 'pagamento') {
                    $r['motivo'] = t('Pagamento da fatura: não vira compra. Lance o pagamento na conta que pagou.');
                } elseif ($l['data'] < '2015-01-01' || $l['data'] > $fimPlano) {
                    $r['motivo'] = t('A data fica fora dos meses do plano.');
                }
            } else {
                $r['tipo'] = $r['valor'] > 0 ? 'entrada' : 'gasto';
                $identidade = implode('|', ['conta', (int) $destino['id'], $l['data'], number_format($r['valor'], 2, '.', ''), self::normalizar($desc), $r['fitid']]);
                if (LancamentosController::validarData($l['data']) !== null) {
                    $r['motivo'] = t('A data fica fora dos meses do plano.');
                }
            }
            $vistas[$identidade] = ($vistas[$identidade] ?? 0) + 1;
            $r['ordem'] = $vistas[$identidade];
            $r['chave'] = substr(hash('sha256', $identidade . '|' . $r['ordem']), 0, 40);
            $out[] = $r;
        }

        self::marcarRepetidas($out, $destino);
        self::sugerir($out);
        foreach ($out as &$r) {
            $r['pode'] = $r['motivo'] === null;
            $e = $escolhas[$r['i']] ?? null;
            // repetida ou com o valor fora do padrão da coluna: vem desmarcada, e a pessoa decide
            $r['marcada'] = $r['pode'] && (is_array($e) ? (bool) ($e['m'] ?? false) : $r['dup'] === null && !$r['duvida']);
            $c = is_array($e) ? (int) ($e['c'] ?? 0) : 0;
            $r['categoria_id'] = $c > 0 && isset(self::permitidas($r['tipo'])[$c]) ? $c : $r['sugestao'];
        }
        unset($r);
        return $out;
    }

    /** "já importada" (a identidade está gravada) ou "parece já lançada" (mesma data, valor e conta, ou a mesma compra). */
    private static function marcarRepetidas(array &$linhas, array $destino): void
    {
        if ($linhas === []) {
            return;
        }
        $gravadas = ImportacaoModel::chavesGravadas($destino['tipo'], array_column($linhas, 'chave'));
        foreach ($linhas as &$r) {
            if (isset($gravadas[$r['chave']])) {
                $r['dup'] = 'importada';
            }
        }
        unset($r);
        // o que já está no destino e não é uma das identidades deste arquivo: cada um serve a uma linha só
        if ($destino['tipo'] === 'cartao') {
            $existentes = array_values(array_filter(ImportacaoModel::comprasDoCartao((int) $destino['id']), static fn(array $c): bool => $c['chave'] === null || !isset($gravadas[$c['chave']])));
            foreach ($linhas as &$r) {
                if ($r['dup'] !== null || $r['tipo'] === 'pagamento') {
                    continue;
                }
                $iguais = array_filter($existentes, static fn(array $c): bool => match ($r['tipo']) {
                    'parcela' => $c['parcelas'] === $r['n'] && $c['primeira_fatura'] === $r['primeira'] && abs($c['valor_total'] - $r['total']) <= 0.01 * $r['n'] + 0.005,
                    'estorno' => $c['parcelas'] === 1 && $c['data'] === $r['data'] && abs($c['valor_total'] + $r['total']) < 0.005,
                    default   => $c['parcelas'] === 1 && $c['data'] === $r['data'] && abs($c['valor_total'] - $r['total']) < 0.005,
                });
                self::casar($r, $existentes, $iguais, (string) $r['gravar']);
            }
            unset($r);
            return;
        }
        $datas = array_column($linhas, 'data');
        $existentes = array_values(array_filter(ImportacaoModel::lancamentosDaConta((int) $destino['id'], min($datas), max($datas)),
            static fn(array $l): bool => $l['chave'] === null || !isset($gravadas[$l['chave']])));
        foreach ($linhas as &$r) {
            if ($r['dup'] !== null) {
                continue;
            }
            $iguais = array_filter($existentes, static fn(array $l): bool => $l['data'] === $r['data'] && abs($l['valor'] - abs($r['valor'])) < 0.005
                && $l['sinal'] === ($r['valor'] > 0 ? 1 : -1));
            self::casar($r, $existentes, $iguais, (string) $r['descricao']);
        }
        unset($r);
    }

    /**
     * Entre os já gravados de mesma data e valor ($iguais, chaves de $existentes), o primeiro de descrição parecida
     * marca a linha como "parece já lançada" e não serve a outra linha. Com a descrição bem diferente, não é a mesma
     * coisa: a linha continua marcada, só com a nota "Mesmo valor e data de …" ('mesmo').
     */
    private static function casar(array &$r, array &$existentes, array $iguais, string $descricao): void
    {
        foreach ($iguais as $j => $e) {
            if (self::descricaoParecida($descricao, $e['descricao'], $e['categoria'] ?? [])) {
                [$r['dup'], $r['parecida']] = ['parecida', $e['descricao']];
                unset($existentes[$j]);
                return;
            }
        }
        if ($iguais !== []) {
            $r['mesmo'] = (string) reset($iguais)['descricao'];
        }
    }

    /** Palavras que quase todo extrato repete e não dizem o que foi. */
    private const PALAVRAS_COMUNS = ['compra', 'compras', 'pix', 'ted', 'doc', 'pagamento', 'pagto', 'pgto', 'debito', 'credito', 'cartao',
        'transferencia', 'transf', 'enviado', 'enviada', 'recebido', 'recebida', 'loja', 'com', 'para', 'dos', 'das', 'nacional', 'parc', 'parcela'];

    /**
     * A descrição do arquivo e a de um lançamento já gravado falam da mesma coisa: uma contém a outra, têm uma palavra
     * em comum (fora as comuns) ou são quase iguais. Vazia, ou só o nome da categoria (o que o lançar grava quando a
     * pessoa não escreve nada), conta como parecida.
     * @param list<string> $nomesCategoria o nome e o apelido da categoria do lançamento gravado
     */
    public static function descricaoParecida(string $arquivo, string $gravada, array $nomesCategoria = []): bool
    {
        $a = self::normalizar($arquivo);
        $b = self::normalizar($gravada);
        foreach ($nomesCategoria as $n) {
            if ($b !== '' && self::normalizar((string) $n) === $b) {
                $b = '';
            }
        }
        if ($a === '' || $b === '' || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        $palavras = static fn(string $s): array => array_diff(array_filter(explode(' ', $s), static fn(string $p): bool => strlen($p) >= 3), self::PALAVRAS_COMUNS);
        if (array_intersect($palavras($a), $palavras($b)) !== []) {
            return true;
        }
        similar_text($a, $b, $pct);
        return $pct >= 70.0;
    }

    /**
     * As categorias que cabem num tipo de linha: id => categoria.
     * @return array<int, array>
     */
    public static function permitidas(string $tipo): array
    {
        if (isset(self::$permitidas[$tipo])) {
            return self::$permitidas[$tipo];
        }
        $fatura = CartaoModel::categoriaFatura();
        $out = [];
        foreach (CategoriaModel::ativas() as $c) {
            $ok = match ($tipo) {
                'gasto'   => (int) $c['sinal'] === -1,
                'entrada' => (int) $c['sinal'] === 1,
                'compra', 'parcela', 'estorno' => CartaoModel::aceitaCompra($c, $fatura),
                default   => false,
            };
            if ($ok) {
                $out[(int) $c['id']] = $c;
            }
        }
        return self::$permitidas[$tipo] = $out;
    }

    /** Esquece as categorias guardadas (depois de criar uma categoria, nos testes). */
    public static function limparCache(): void
    {
        self::$permitidas = [];
    }

    /** Põe 'sugestao' (id ou null), 'origem' ('csv', 'regra', 'memoria' ou null) e 'regra' (o texto) em cada linha. */
    private static function sugerir(array &$linhas): void
    {
        $regras = [];
        foreach (ImportacaoModel::regras() as $rg) {
            $norm = self::normalizar($rg['contem']);
            if ($norm !== '') {
                $regras[] = ['norm' => $norm, 'texto' => $rg['contem'], 'categoria_id' => $rg['categoria_id']];
            }
        }
        usort($regras, static fn(array $a, array $b): int => strlen($b['norm']) <=> strlen($a['norm']));
        $memoria = [];
        $desde = date('Y-m-d', strtotime(Relogio::hoje() . ' -' . self::MEMORIA_ANOS . ' years'));
        foreach (ImportacaoModel::memoria($desde) as $m) {
            $norm = self::normalizar(($p = self::parcela($m['descricao'])) !== null ? $p['base'] : $m['descricao']);
            if ($norm === '') {
                continue;
            }
            $atual = $memoria[$norm][$m['categoria_id']] ?? ['n' => 0, 'ultima' => ''];
            $memoria[$norm][$m['categoria_id']] = ['n' => $atual['n'] + $m['n'], 'ultima' => max($atual['ultima'], $m['ultima'])];
        }
        $nomes = [];
        foreach (CategoriaModel::ativas() as $c) {
            foreach (array_filter([(string) $c['nome'], (string) ($c['apelido'] ?? ''), nomeCategoria((string) $c['nome'])]) as $n) {
                $nomes[Colacao::dobrar(trim($n))] ??= (int) $c['id'];
            }
        }
        foreach ($linhas as &$r) {
            [$r['sugestao'], $r['origem'], $r['regra']] = [null, null, null];
            $ok = self::permitidas($r['tipo']);
            if ($ok === []) {
                continue;
            }
            $csv = $r['categoria_csv'] !== '' ? ($nomes[Colacao::dobrar($r['categoria_csv'])] ?? null) : null;
            if ($csv !== null && isset($ok[$csv])) {
                [$r['sugestao'], $r['origem']] = [$csv, 'csv'];
                continue;
            }
            $norm = self::normalizar($r['gravar']);
            foreach ($regras as $rg) {
                if (isset($ok[$rg['categoria_id']]) && str_contains(' ' . $norm . ' ', $rg['norm'])) {
                    [$r['sugestao'], $r['origem'], $r['regra']] = [$rg['categoria_id'], 'regra', $rg['texto']];
                    continue 2;
                }
            }
            $melhor = null;
            foreach ($memoria[$norm] ?? [] as $cat => $x) {
                if (isset($ok[$cat]) && ($melhor === null || [$x['n'], $x['ultima']] > [$melhor[1]['n'], $melhor[1]['ultima']])) {
                    $melhor = [$cat, $x];
                }
            }
            if ($melhor !== null) {
                [$r['sugestao'], $r['origem']] = [(int) $melhor[0], 'memoria'];
            }
        }
        unset($r);
    }

    /**
     * Grava as linhas marcadas num lote. Toda linha marcada precisa de categoria do tipo dela; senão nada é gravado.
     * $info = ['nome' => arquivo, 'formato' => 'ofx'|'csv', 'hash' => sha256].
     * @param list<array> $revisao saída de revisao(), com marcada e categoria_id já das escolhas
     * @return array{erro:?string, id:int, n:int}
     */
    public static function gravar(array $revisao, array $destino, array $info, int $usuarioId): array
    {
        $marcadas = array_values(array_filter($revisao, static fn(array $r): bool => $r['marcada'] && $r['pode']));
        if ($marcadas === []) {
            return ['erro' => t('Marque ao menos uma linha.'), 'id' => 0, 'n' => 0];
        }
        $semCategoria = count(array_filter($marcadas, static fn(array $r): bool => $r['categoria_id'] === null || !isset(self::permitidas($r['tipo'])[$r['categoria_id']])));
        if ($semCategoria > 0) {
            return ['erro' => tn($semCategoria, 'Falta a categoria de 1 linha marcada.', 'Falta a categoria de {n} linhas marcadas.'), 'id' => 0, 'n' => 0];
        }
        $cartao = $destino['tipo'] === 'cartao';
        // os resgates do arquivo, somados por categoria, não passam do que estava guardado
        $resgates = [];
        foreach ($cartao ? [] : $marcadas as $r) {
            $resgates[$r['categoria_id']] = [($resgates[$r['categoria_id']][0] ?? 0.0) + abs($r['valor']), max($resgates[$r['categoria_id']][1] ?? '', $r['data'])];
        }
        foreach ($resgates as $cat => [$soma, $ate]) {
            if (($erro = Investimentos::erroResgate((int) $cat, round($soma, 2), $ate)) !== null) {
                return ['erro' => $erro, 'id' => 0, 'n' => 0];
            }
        }
        $pdo = Database::connection();
        $abriu = !$pdo->inTransaction();             // dentro de outra transação (os testes), fica nela
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            $id = ImportacaoModel::criar($cartao ? null : (int) $destino['id'], $cartao ? (int) $destino['id'] : null, $info['nome'], $info['formato'], $info['hash'], $usuarioId);
            foreach ($marcadas as $r) {
                $cat = self::permitidas($r['tipo'])[$r['categoria_id']];
                if ($cartao) {
                    $desc = $r['gravar'] !== '' ? $r['gravar'] : nomeCurtoBanco($cat);
                    $total = $r['tipo'] === 'estorno' ? -$r['total'] : $r['total'];
                    $linha = CartaoModel::criarCompra((int) $destino['id'], $r['data'], $desc, (int) $cat['id'], $total, $r['n'], (string) $r['primeira'], $usuarioId);
                } else {
                    MesModel::ensureExists(substr($r['data'], 0, 8) . '01');
                    $desc = $r['gravar'] !== '' ? $r['gravar'] : (string) $cat['nome'];
                    $linha = LancamentoModel::criar($r['data'], $desc, (int) $cat['id'], abs($r['valor']), $usuarioId, (int) $destino['id']);
                }
                ImportacaoModel::marcar($linha, $cartao, $id, $r['chave']);
            }
            ImportacaoModel::contarLinhas($id, count($marcadas));
            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($cartao) {
            CartaoFatura::sincronizarPlano($usuarioId);
        }
        MotorFinanceiro::limparCache();
        return ['erro' => null, 'id' => $id, 'n' => count($marcadas)];
    }

    /** "Desfazer importação": apaga o lote inteiro. @return int quantas linhas saíram */
    public static function desfazer(int $id, int $usuarioId): int
    {
        $imp = ImportacaoModel::achar($id);
        if ($imp === null || $imp['desfeita_em'] !== null) {
            return 0;
        }
        $pdo = Database::connection();
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            $n = ImportacaoModel::desfazer($id);
            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($imp['cartao_id'] !== null) {
            CartaoFatura::sincronizarPlano($usuarioId);
        }
        MotorFinanceiro::limparCache();
        return $n;
    }
}
