<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Relogio;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\Horizonte;
use Financeiro\Site;
use Financeiro\View;

/**
 * A primeira configuração, no site: moeda, fuso, início e saldos, renda, o que a pessoa usa e contas fixas. Só o 1º
 * passo é obrigatório. Num início no meio do mês, o que venceu antes dele não entra na previsão daquele mês.
 * Ao lado das perguntas, a prévia do mês se monta com o que já foi respondido (previa()); no fim, a chegada mostra o
 * primeiro "Dá para gastar", com a mesma conta do Início (DashboardController::resposta).
 * Os campos mostram o que foi digitado, depois de um erro, ou o que já está salvo, ao voltar um passo (campos()). Por
 * isso, num passo já salvo, campo vazio não muda nada: "Voltar" e "Continuar" sem digitar não apagam o que havia.
 */
final class ComecarController
{
    private const PASSOS = 4;

    public function index(): void
    {
        Auth::requireLogin();
        if (AjusteModel::configurado()) {
            if (isset($_GET['pronto'])) {
                View::renderAuth('comecar/pronto', ['chegada' => self::chegada(), 'emprestimo' => AjusteModel::usa('usa_emprestimo')]);
                return;
            }
            redirecionar('/', 302);
        }
        $passo = max(1, min(self::PASSOS, (int) ($_GET['passo'] ?? 1)));
        if ($passo > 1 && MesModel::primeiro() === null) {
            redirecionar('/comecar', 302);
        }
        View::renderAuth('comecar/index', [
            'passo' => $passo, 'total' => self::PASSOS, 'hoje' => Relogio::hoje(),
            'inicio' => AjusteModel::dataInicio() ?? Relogio::hoje(),
            'contas' => $passo === 4 ? self::contasFixas() : [],
            'usos' => self::usos(),
            'campos' => self::campos($passo),
            'moeda' => Site::moeda(), 'fuso' => Site::fuso(), 'fusoEscolhido' => Site::fusoGravado(),
            'erro' => self::erro(),
            'previa' => self::previa(),
        ]);
    }

    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        if (AjusteModel::configurado()) {
            redirecionar('/', 303);
        }
        $passo = (int) ($_POST['passo'] ?? 1);
        match ($passo) {
            2 => self::salvarRenda(),
            3 => self::salvarUsos(),
            4 => self::concluir(),
            default => self::salvarInicio(),
        };
        redirecionar('/comecar?passo=' . ($passo + 1), 303);
    }

    private static function salvarInicio(): void
    {
        // o fuso vem antes da data: "hoje" é o do fuso escolhido
        $moeda = (string) ($_POST['moeda'] ?? '');
        $fuso = (string) ($_POST['fuso'] ?? '');
        if (!Site::moedaValida($moeda) || !Site::fusoValido($fuso)) {
            self::voltarComErro(1, t('Escolha a moeda e o fuso horário.'));
        }
        Site::usarMoeda($moeda);
        Site::usarFuso($fuso);
        $data = is_string($_POST['data_inicio'] ?? null) ? $_POST['data_inicio'] : '';
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        $problema = $ok ? Horizonte::problemaNaData($data) : t('Escolha uma data válida.');
        $jaSalvo = self::feito();
        $valores = [];
        foreach (['saldo_ini', 'poupanca_ini', 'investimentos_ini'] as $chave) {
            $bruto = trim(is_string($_POST[$chave] ?? null) ? $_POST[$chave] : '');
            if ($bruto === '' && $jaSalvo) {
                continue;                                       // voltou ao passo e deixou vazio: fica o que estava
            }
            $v = $bruto === '' ? 0.0 : parseMoedaBr($bruto);
            if ($v === null || ($chave !== 'saldo_ini' && $v < 0)) {
                $problema = t('Confira os valores: use números como 1.200,50.');
            }
            $valores[$chave] = (float) $v;
        }
        if ($problema !== null) {
            self::voltarComErro(1, $problema);
        }
        Site::salvarMoeda($moeda);
        Site::salvarFuso($fuso);
        foreach ($valores as $chave => $v) {
            if ($chave === 'saldo_ini') {
                ContaModel::saldoInicialDaUnica(round($v, 2));     // o saldo inicial do plano é a soma das contas
                continue;
            }
            AjusteModel::setValor($chave, round($v, 4));
        }
        if (!$jaSalvo) {
            // até o 3º passo, nada está marcado: a prévia não mostra cartão, empréstimo nem carro que ninguém escolheu
            AjusteModel::setValor('usa_cartao', 0.0);
            AjusteModel::setValor('usa_emprestimo', 0.0);
            AjusteModel::setValor('usa_carro', 0.0);
            CategoriaModel::marcarCarro(false);
        }
        Horizonte::mudarInicio($data);
    }

    private static function salvarRenda(): void
    {
        $bruto = trim((string) ($_POST['renda'] ?? ''));
        if ($bruto === '') {
            return;
        }
        $v = parseMoedaBr($bruto);
        $dia = self::dia($_POST['dia'] ?? '');
        if ($v === null || $v < 0 || $dia === false) {
            self::voltarComErro(2, t('Confira os valores: use números como 1.200,50.'));
        }
        $cat = CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO);
        if ($cat !== null) {
            self::preverEmTodos((int) $cat['id'], $v, $dia);
        }
        $_SESSION['comecar_dia_renda'] = $dia;                  // o dia não fica gravado: Voltar o mostra daqui
    }

    private static function salvarContas(): void
    {
        $valores = (array) ($_POST['valor'] ?? []);
        $dias = (array) ($_POST['dia'] ?? []);
        // "adicionar outra conta fixa": cada linha nova com nome vira uma categoria de conta fixa (grupo Fixos),
        // e o valor e o dia seguem o caminho das outras. Confere tudo antes de criar qualquer uma.
        $novas = [];
        $nomesNovos = (array) ($_POST['nova_nome'] ?? []);
        foreach ($nomesNovos as $i => $bruto) {
            $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', is_string($bruto) ? $bruto : ''));
            if ($nome === '') {
                continue;
            }
            $existente = CategoriaModel::porNome($nome);
            if (mb_strlen($nome) > 100 || ($existente !== null && ($existente['grupo'] ?? '') !== 'Fixos')) {
                self::voltarComErro(4, t('Já existe uma categoria chamada {nome}.', ['nome' => $nome]));
            }
            $valorNovo = (array) ($_POST['nova_valor'] ?? []);
            $diaNovo = (array) ($_POST['nova_dia'] ?? []);
            $novas[] = ['nome' => $nome, 'id' => $existente !== null ? (int) $existente['id'] : null,
                'valor' => is_string($valorNovo[$i] ?? null) ? $valorNovo[$i] : '', 'dia' => is_string($diaNovo[$i] ?? null) ? $diaNovo[$i] : ''];
        }
        foreach ($novas as $n) {
            $bruto = trim($n['valor']);
            if (($bruto !== '' && (parseMoedaBr($bruto) ?? -1) < 0) || self::dia($n['dia']) === false) {
                self::voltarComErro(4, t('Confira os valores: use números como 1.200,50.'));
            }
        }
        foreach ($novas as $n) {
            $id = $n['id'] ?? CategoriaModel::criar($n['nome'], 'saida', true, null, true);
            if ($id === null) {
                continue;
            }
            $valores[$id] = $n['valor'];
            $dias[$id] = $n['dia'];
        }
        $validas = array_column(self::contasFixas(), null, 'id');
        foreach ($valores as $id => $bruto) {
            $id = (int) $id;
            $bruto = trim(is_string($bruto) ? $bruto : '');
            if ($bruto === '' || !isset($validas[$id])) {
                continue;
            }
            $v = parseMoedaBr($bruto);
            $dia = self::dia($dias[$id] ?? '');
            if ($v === null || $v < 0 || $dia === false) {
                self::voltarComErro(4, t('Confira os valores: use números como 1.200,50.'));
            }
            self::preverEmTodos($id, $v, $dia);
            if ($dia !== null) {
                ContaRecorrenteModel::upsert($id, $dia);
            }
        }
    }

    private static function salvarUsos(): void
    {
        $usos = [
            'cartao' => ($_POST['usa_cartao'] ?? '') === '1',
            'emprestimo' => ($_POST['usa_emprestimo'] ?? '') === '1',
            'carro' => ($_POST['tem_carro'] ?? '') === '1',
            'parcela' => ($_POST['parcela_modo'] ?? '') === '1' ? 1 : 2,
            'objetivo' => ($_POST['objetivo'] ?? '') === '1' ? 1 : 3,
        ];
        AjusteModel::setValor('usa_cartao', $usos['cartao'] ? 1.0 : 0.0);
        AjusteModel::setValor('usa_emprestimo', $usos['emprestimo'] ? 1.0 : 0.0);
        AjusteModel::setValor('parcela_modo', (float) $usos['parcela']);
        AjusteModel::setValor('objetivo', (float) $usos['objetivo']);
        AjusteModel::setValor('usa_carro', $usos['carro'] ? 1.0 : 0.0);
        CategoriaModel::marcarCarro($usos['carro']);
        $_SESSION['comecar_usos'] = $usos;   // Voltar mostra o que foi marcado
    }

    /**
     * O que o 3º passo mostra marcado: o que a pessoa marcou (Voltar) ou o que vale agora, que o 1º passo deixou
     * desligado. O que está na tela é o que se grava, também em "Pular este passo", que envia o formulário como está.
     * @return array<string, bool|int>
     */
    private static function usos(): array
    {
        if (is_array($_SESSION['comecar_usos'] ?? null)) {
            return $_SESSION['comecar_usos'];
        }
        return ['cartao' => AjusteModel::usa('usa_cartao'), 'emprestimo' => AjusteModel::usa('usa_emprestimo'), 'carro' => AjusteModel::usa('usa_carro')];
    }

    /**
     * O valor de cada campo do passo: o que foi digitado, se o passo voltou com erro, ou o que já está salvo (o saldo,
     * a poupança e os investimentos do 1º passo; a renda e o dia do 2º). Campo sem nada salvo fica vazio, com o exemplo.
     * @return array<string, string|array<int|string, string>>
     */
    private static function campos(int $passo): array
    {
        $digitado = $_SESSION['comecar_digitado'] ?? null;
        unset($_SESSION['comecar_digitado']);
        if (is_array($digitado) && ($digitado['passo'] ?? 0) === $passo && is_array($digitado['campos'] ?? null)) {
            return $digitado['campos'];
        }
        if (!self::feito()) {
            return [];
        }
        if ($passo === 1) {
            $outro = static fn(string $k): string => AjusteModel::valor($k) != 0.0 ? numInput(AjusteModel::valor($k)) : '';
            return ['saldo_ini' => numInput(AjusteModel::valor('saldo_ini')), 'poupanca_ini' => $outro('poupanca_ini'), 'investimentos_ini' => $outro('investimentos_ini')];
        }
        if ($passo === 2) {
            $renda = self::previa()['renda'];
            $dia = $_SESSION['comecar_dia_renda'] ?? null;
            return $renda !== null && $renda['valor'] > 0
                ? ['renda' => numInput((float) $renda['valor']), 'dia' => is_int($dia) ? (string) $dia : '']
                : [];
        }
        return [];
    }

    /** O 1º passo já foi salvo: o início e os meses existem. */
    private static function feito(): bool
    {
        return MesModel::primeiro() !== null && AjusteModel::dataInicio() !== null;
    }

    /** Último passo: as contas fixas (ou nada, em "Pular") e o caminho até o primeiro uso. */
    private static function concluir(): void
    {
        if (($_POST['pular'] ?? '') !== '1') {
            self::salvarContas();
        }
        AjusteModel::setValor('configurado', 1.0);
        unset($_SESSION['comecar_usos'], $_SESSION['comecar_dia_renda'], $_SESSION['comecar_digitado']);
        // a chegada: o mês montado e o primeiro "Dá para gastar"; de lá, o Início (ou os dados do empréstimo)
        redirecionar('/comecar?pronto=1', 303);
    }

    /**
     * A prévia do mês ao lado das perguntas, com o que já foi respondido: o saldo do início, a renda e as contas
     * fixas do primeiro mês. Linha que venceu antes do início vem com 'antes' = true: o valor já está no saldo e
     * não entra na conta. Antes do 1º passo, só o mês.
     * @return array{mes:string, diaIni:int, saldo:?float, renda:?array{valor:float, antes:bool}, contas:list<array{id:int, nome:string, valor:float, dia:?int, antes:bool}>, usos:array<string,bool>}
     */
    private static function previa(): array
    {
        $inicio = AjusteModel::dataInicio();
        $feito = self::feito();
        $mes = substr($inicio ?? Relogio::hoje(), 0, 7) . '-01';
        $meses = array_column(MesModel::todos(), 'mes');
        $seguinte = $meses[array_search($mes, $meses, true) + 1] ?? $mes;     // o valor "de todo mês", sem o corte do início
        // valor no 1º mês; zero lá com valor no seguinte = venceu antes do início, já está no saldo
        $linha = static function (int $cat) use ($mes, $seguinte): array {
            $noMes = PrevisaoModel::valor($cat, $mes);
            $todoMes = PrevisaoModel::valor($cat, $seguinte);
            return ['valor' => $noMes > 0 ? $noMes : $todoMes, 'antes' => $noMes <= 0 && $todoMes > 0];
        };
        $salario = CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO);
        $contas = [];
        if ($feito) {
            foreach (self::contasFixas() as $c) {
                $l = $linha($c['id']);
                $rec = ContaRecorrenteModel::daCategoria($c['id']);
                $contas[] = $c + $l + ['dia' => $rec !== null ? (int) $rec['dia_vencimento'] : null];
            }
        }
        return [
            'mes' => $mes,
            'diaIni' => (int) substr($inicio ?? Relogio::hoje(), 8, 2),
            'saldo' => $feito ? AjusteModel::valor('saldo_ini') : null,
            'renda' => $feito && $salario !== null ? $linha((int) $salario['id']) : null,
            'contas' => $contas,
            'usos' => ['cartao' => AjusteModel::usa('usa_cartao'), 'emprestimo' => AjusteModel::usa('usa_emprestimo')],
        ];
    }

    /**
     * O primeiro "Dá para gastar", com a conta do Início: a previsão do fim do mês menos a reserva (e, com o
     * empréstimo sendo adiantado, a previsão sem o adiantamento).
     * @return ?array{tipo:string, valor:float, adianta:float, mes:string, reserva:float, fim:float}
     */
    private static function chegada(): ?array
    {
        $hoje = Relogio::hoje();
        $plano = MotorFinanceiro::calcularPlano();
        if ($plano === []) {
            return null;
        }
        $mes = MesModel::referencia($hoje);
        if (!isset($plano[$mes])) {
            $mes = (string) array_key_last($plano);
        }
        $linha = $plano[$mes];
        if ($linha['situacao'] === 'REAL') {
            return null;
        }
        $fim = (float) $linha['fica_na_conta'];
        $semAdiantar = $fim;
        if (AjusteModel::usa('usa_emprestimo') && (int) round(AjusteModel::valor('objetivo', 1.0)) !== 3) {
            $semAdiantar = (float) (MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3, 'hoje' => $hoje])[$mes]['fica_na_conta'] ?? $fim);
        }
        $reserva = AjusteModel::valor('reserva_minima');
        $r = DashboardController::resposta($fim, $semAdiantar, $reserva);
        return $r === null ? null : $r + ['mes' => $mes, 'reserva' => max(0.0, $reserva), 'fim' => $fim];
    }

    /** Mesmo valor em todos os meses; no 1º, zero se a data dele já passou quando o plano começou. */
    private static function preverEmTodos(int $cat, float $valor, ?int $dia): void
    {
        $inicio = AjusteModel::dataInicio();
        $mesIni = $inicio !== null ? substr($inicio, 0, 8) . '01' : '';
        foreach (MesModel::todos() as $m) {
            $jaPassou = (string) $m['mes'] === $mesIni && $dia !== null && $inicio !== null && $dia < (int) substr($inicio, 8, 2);
            PrevisaoModel::setValor($cat, (string) $m['mes'], $jaPassou ? 0.0 : round($valor, 2));
        }
    }

    /** @return int|null|false o dia, null se vazio, false se inválido */
    private static function dia(mixed $bruto): int|null|false
    {
        $s = trim(is_string($bruto) ? $bruto : '');
        if ($s === '') {
            return null;
        }
        return ctype_digit($s) && (int) $s >= 1 && (int) $s <= 31 ? (int) $s : false;
    }

    /**
     * As contas que se aplicam: as fixas e, para quem marcou cartão, a fatura.
     * @return list<array{id:int, nome:string}>
     */
    private static function contasFixas(): array
    {
        $out = [];
        $grupos = AjusteModel::usa('usa_cartao') ? "'Fixos', 'Cartao'" : "'Fixos'";
        $st = Database::connection()->query("SELECT id, nome, apelido, grupo FROM categorias WHERE grupo IN ({$grupos}) AND ativo = 1 ORDER BY ordem, nome");
        foreach ($st as $c) {
            $out[] = ['id' => (int) $c['id'], 'nome' => $c['grupo'] === 'Cartao' ? nomeCategoria((string) $c['nome']) : nomeCurto($c)];   // "Fatura" sozinha não diz de quê
        }
        return $out;
    }

    /** Volta ao passo com a mensagem e com o que foi digitado (só texto: o que veio de outro jeito é ignorado). */
    private static function voltarComErro(int $passo, string $msg): never
    {
        $_SESSION['comecar_erro'] = $msg;
        $texto = static fn(mixed $v): string => is_string($v) ? mb_substr($v, 0, 100) : '';
        $campos = [];
        foreach (['data_inicio', 'saldo_ini', 'poupanca_ini', 'investimentos_ini', 'moeda', 'fuso', 'renda', 'dia', 'valor', 'nova_nome', 'nova_valor', 'nova_dia'] as $k) {
            if (isset($_POST[$k])) {
                $campos[$k] = is_array($_POST[$k]) ? array_map($texto, array_slice($_POST[$k], 0, 200, true)) : $texto($_POST[$k]);
            }
        }
        $_SESSION['comecar_digitado'] = ['passo' => $passo, 'campos' => $campos];
        redirecionar('/comecar?passo=' . $passo, 303);
    }

    private static function erro(): ?string
    {
        $m = $_SESSION['comecar_erro'] ?? null;
        unset($_SESSION['comecar_erro']);
        return is_string($m) ? $m : null;
    }
}
