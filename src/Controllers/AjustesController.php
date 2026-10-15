<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\View;

/**
 * Ajustes (SPEC §2.8, menu do avatar) — dono: P5. Parâmetros do motor, ponto de partida,
 * contas recorrentes, essenciais, aparelhos e senha. Cada grupo tem o seu SALVAR.
 */
final class AjustesController
{
    /** Grupos (<details class="dobra">) e se abrem por padrão. */
    private const GRUPOS = ['parametros' => true, 'partida' => false, 'contas' => true, 'essenciais' => true, 'aparelhos' => true, 'senha' => false];

    public function index(): void
    {
        Auth::requireLogin();

        $aj = [];
        foreach (AjusteModel::todos() as $a) {
            $aj[$a['chave']] = ['label' => (string) $a['label'], 'valor' => (float) $a['valor'], 'confirmado' => (bool) $a['confirmado']];
        }
        $erro = $_SESSION['aj_erro'] ?? null;
        unset($_SESSION['aj_erro']);

        $abertos = self::GRUPOS;
        $pedido = $_GET['aberto'] ?? null;
        if (is_string($pedido) && isset($abertos[$pedido])) {
            $abertos[$pedido] = true;
        }
        if (is_array($erro) && isset($abertos[$erro['grupo'] ?? ''])) {
            $abertos[$erro['grupo']] = true;
        }

        $recorrentes = ContaRecorrenteModel::ativas();
        $jaTem = array_flip(array_map(static fn($r) => (int) $r['categoria_id'], $recorrentes));
        $elegiveis = array_values(array_filter(ContaRecorrenteModel::categoriasElegiveis(), static fn($c) => !isset($jaTem[$c['id']])));
        $apelidos = [];
        foreach (CategoriaModel::ativas() as $c) {
            $apelidos[(int) $c['id']] = $c;
        }

        View::render('ajustes/index', [
            'pageTitle'   => 'ajustes · financeiro',
            'aj'          => $aj,
            'taxa'        => AjusteModel::taxaPeloCredito(),
            'ultimo'      => MesModel::ultimo(),
            'recorrentes' => $recorrentes,
            'apelidos'    => $apelidos,
            'elegiveis'   => $elegiveis,
            'gastos'      => CategoriaModel::gastos(),
            'abertos'     => $abertos,
            'erro'        => is_array($erro) ? $erro : null,
            'login'       => (string) (\Financeiro\Models\UsuarioModel::find((int) Auth::id())['login'] ?? ''),
        ], 'ajustes');
    }

    /* -------------------------------------------------------------- parâmetros e ponto de partida */

    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $grupo = ($_POST['grupo'] ?? '') === 'partida' ? 'partida' : 'parametros';
        $valores = [];
        foreach ((array) ($_POST['valores'] ?? []) as $chave => $bruto) {
            $chave = (string) $chave;
            if (!in_array($chave, AjusteModel::EDITAVEIS, true)) {
                continue;                                       // fora da whitelist: ignorado
            }
            if ($chave === 'consignado_inicio') {               // <input type="month">: "AAAA-MM" → AAAAMM; vazio = desde o início
                $bruto = is_string($bruto) ? trim($bruto) : '';
                if ($bruto !== '' && !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $bruto, $m)) {
                    $_SESSION['aj_erro'] = ['grupo' => $grupo, 'msg' => self::rotulo($chave) . '?', 'campo' => $chave, 'valores' => (array) $_POST['valores']];
                    redirecionar('/ajustes?aberto=' . $grupo . '#' . $grupo, 303);
                }
                $valores[$chave] = $bruto === '' ? 0.0 : (float) ((int) $m[1] * 100 + (int) $m[2]);
                continue;
            }
            $v = parseMoedaBr(is_string($bruto) ? $bruto : '');
            $ok = $v !== null && match ($chave) {
                'saldo_ini'  => true,                           // a conta pode começar negativa
                'n_parcelas' => $v >= 1 && abs($v - round($v)) < 1e-9,
                'juros_mes'  => $v >= 0 && $v < 100,
                default      => $v >= 0,
            };
            if (!$ok) {
                $_SESSION['aj_erro'] = ['grupo' => $grupo, 'msg' => self::rotulo($chave) . '?', 'campo' => $chave, 'valores' => (array) $_POST['valores']];
                redirecionar('/ajustes?aberto=' . $grupo . '#' . $grupo, 303);
            }
            $valores[$chave] = $chave === 'juros_mes' ? round($v / 100, 6) : round($v, 4);   // o campo mostra %, o banco guarda a fração
        }

        foreach ($valores as $chave => $v) {
            AjusteModel::setValor($chave, $v);                  // preserva `confirmado`
        }
        if (array_key_exists('juros_mes', $valores)) {
            AjusteModel::setConfirmado('juros_mes', ($_POST['confirmado']['juros_mes'] ?? '') === '1');   // só o juros tem "confirmado"
        }

        flash('success', $valores === [] ? 'nada mudou' : 'ajustes salvos');
        redirecionar('/ajustes?aberto=' . $grupo . '#' . $grupo, 303);
    }

    /* -------------------------------------------------------------- contas recorrentes */

    public function salvarConta(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $cat = (int) ($_POST['categoria_id'] ?? 0);
        $dia = (int) ($_POST['dia_vencimento'] ?? 0);
        $elegivel = null;
        foreach (ContaRecorrenteModel::categoriasElegiveis() as $c) {
            if ($c['id'] === $cat) {
                $elegivel = $c;
            }
        }
        if ($elegivel === null || $dia < 1 || $dia > 31) {
            $_SESSION['aj_erro'] = ['grupo' => 'contas', 'msg' => $elegivel === null ? 'conta?' : 'dia?'];
            redirecionar('/ajustes?aberto=contas#contas', 303);
        }
        ContaRecorrenteModel::upsert($cat, $dia);
        flash('success', nomeCurto($elegivel) . ' · dia ' . $dia);
        redirecionar('/ajustes?aberto=contas#contas', 303);
    }

    public function removerConta(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $conta = ContaRecorrenteModel::buscar((int) $id);
        if ($conta === null || (int) $conta['ativo'] !== 1) {
            flash('warn', 'já removida');
            redirecionar('/ajustes?aberto=contas#contas', 303);
        }
        ContaRecorrenteModel::desativar((int) $id);
        flash('success', nomeCurto(['nome' => $conta['categoria_nome'], 'apelido' => $conta['apelido']]) . ' removida', [
            'url' => '/ajustes/contas', 'label' => 'desfazer',
            'campos' => ['categoria_id' => (string) $conta['categoria_id'], 'dia_vencimento' => (string) $conta['dia_vencimento']],
        ]);
        redirecionar('/ajustes?aberto=contas#contas', 303);
    }

    /* -------------------------------------------------------------- essenciais */

    public function salvarEssenciais(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $marcadas = array_flip(array_map('intval', (array) ($_POST['essencial'] ?? [])));
        foreach (CategoriaModel::gastos() as $c) {
            CategoriaModel::setEssencial((int) $c['id'], isset($marcadas[(int) $c['id']]));
        }
        flash('success', 'essenciais salvos');
        redirecionar('/ajustes?aberto=essenciais#essenciais', 303);
    }

    /* -------------------------------------------------------------- aparelhos e senha */

    public function sairAparelhos(): void
    {
        Auth::requireLogin();
        csrfCheck();

        Auth::sairDeTodos((int) Auth::id());
        flash('success', 'saiu de todos os outros aparelhos');
        redirecionar('/ajustes?aberto=aparelhos#aparelhos', 303);
    }

    public function trocarSenha(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $str     = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        $atual   = $str('senha_atual');
        $nova    = $str('senha_nova');
        $repetir = $str('senha_repetir') !== '' ? $str('senha_repetir') : $str('senha_confirma');

        // mesmo limite do login (5 erradas → 10 min), contado no login deste usuário:
        // quem está chutando a senha atual provavelmente não é o dono → sai desta sessão
        $conferida = Auth::conferirSenhaAtual((int) Auth::id(), $atual);
        if ($conferida === 'bloqueado') {
            Auth::logout();
            renderErro(429, 'bloqueado', 'senha atual errada demais · entre de novo em 10 min');
        }
        $erro = match (true) {
            $conferida !== 'ok'   => 'senha atual?',
            mb_strlen($nova) < 6  => 'nova: mínimo 6',
            $nova !== $repetir    => 'repetir não bate',
            default               => null,
        };
        if ($erro !== null) {
            $_SESSION['aj_erro'] = ['grupo' => 'senha', 'msg' => $erro];
            redirecionar('/ajustes?aberto=senha#senha', 303);
        }

        UsuarioModel::trocarSenha((int) Auth::id(), $nova);
        Auth::sairDeTodos((int) Auth::id());                    // os outros aparelhos precisam da senha nova
        flash('success', 'senha trocada');
        redirecionar('/ajustes', 303);
    }

    private static function rotulo(string $chave): string
    {
        return [
            'reserva_minima' => 'reserva mínima', 'meta_final' => 'meta', 'juros_mes' => 'juros ao mês',
            'parcela_mensal' => 'parcela', 'n_parcelas' => 'nº de parcelas', 'consignado_inicio' => '1ª parcela', 'saldo_ini' => 'conta',
            'poupanca_ini' => 'poupança', 'recebido_emprestimo' => 'crédito recebido',
        ][$chave] ?? $chave;
    }
}
