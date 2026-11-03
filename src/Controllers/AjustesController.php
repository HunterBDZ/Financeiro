<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Relogio;
use Financeiro\Services\Horizonte;
use Financeiro\Services\LinkProduto;
use Financeiro\Site;
use Financeiro\SiteIcone;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\View;

/**
 * Ajustes, em grupos por assunto: dinheiro e planejamento, aparência e idioma, conta e segurança, sistema e sobre.
 * Cada item é uma linha que abre (<details>) ou leva a outra tela, e cada formulário tem o seu SALVAR. As contas
 * fixas ficam em Metas; o essencial, em cada categoria. Os dados do empréstimo ficam na aba Empréstimo
 * (EmprestimoController::salvarDados), que usa a mesma validação.
 */
final class AjustesController
{
    /** Os itens que abrem (<details>), todos fechados: a lista mostra o estado de cada um à direita. ?aberto= abre um. */
    private const GRUPOS = ['categorias' => false, 'carteira' => false, 'parametros' => false, 'recursos' => false, 'partida' => false, 'idioma' => false,
        'regional' => false, 'icone' => false, 'senha' => false, 'duasetapas' => false, 'pessoas' => false, 'aparelhos' => false];

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
        if ($pedido === 'contas') {                             // links antigos: as contas fixas foram para Metas
            redirecionar('/meta#contas-fixas', 302);
        }
        if ($pedido === 'essenciais') {                         // e o essencial, para cada categoria
            $pedido = 'categorias';
        }
        if (is_string($pedido) && isset($abertos[$pedido])) {
            $abertos[$pedido] = true;
        }
        if (is_array($erro) && isset($abertos[$erro['grupo'] ?? ''])) {
            $abertos[$erro['grupo']] = true;
        }

        $dias = [];
        foreach (ContaRecorrenteModel::ativas() as $r) {
            $dias[(int) $r['categoria_id']] = (int) $r['dia_vencimento'];
        }

        View::render('ajustes/index', [
            'pageTitle'   => t('ajustes · MyMonetto'),
            'aj'          => $aj,
            'contas'      => count($dias),
            'dias'        => $dias,
            'categorias'  => CategoriaModel::ativas(),
            'arquivadas'  => CategoriaModel::arquivadas(),
            'abertos'     => $abertos,
            'erro'        => is_array($erro) ? $erro : null,
            'login'       => (string) (\Financeiro\Models\UsuarioModel::find((int) Auth::id())['login'] ?? ''),
            'contasDinheiro' => ContaModel::todas(),
            'saldosContas' => ContaModel::saldos(Relogio::hoje()),
        ], 'ajustes');
    }

    /* -------------------------------------------------------------- início do plano e recursos */

    public function salvarInicio(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $data = is_string($_POST['data_inicio'] ?? null) ? $_POST['data_inicio'] : '';
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        $problema = $ok ? Horizonte::problemaNaData($data) : t('Escolha uma data válida.');
        if ($problema !== null) {
            flash('error', $problema);
            redirecionar('/ajustes?aberto=partida#partida', 303);
        }
        $antes = AjusteModel::dataInicio();
        Horizonte::mudarInicio($data);
        flash('success', $antes !== null && $data < $antes && substr($data, 0, 7) < Relogio::agora()->format('Y-m')
            ? t('Início salvo. Os meses que passaram ficam abertos: lance o que houve e feche cada um.')
            : t('Início salvo.'));
        redirecionar('/ajustes?aberto=partida#partida', 303);
    }

    /** A moeda e o fuso do site: valem para todas as pessoas. Os valores gravados não mudam. */
    public function salvarRegional(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $moeda = (string) ($_POST['moeda'] ?? '');
        $fuso = (string) ($_POST['fuso'] ?? '');
        if (Site::moedaValida($moeda) && Site::fusoValido($fuso)) {
            Site::salvarMoeda($moeda);
            Site::salvarFuso($fuso);
            flash('success', t('Ajustes salvos.'));
        }
        redirecionar('/ajustes?aberto=regional#regional', 303);
    }

    public function salvarRecursos(): void
    {
        Auth::requireLogin();
        csrfCheck();
        AjusteModel::setValor('usa_cartao', ($_POST['usa_cartao'] ?? '') === '1' ? 1.0 : 0.0);
        AjusteModel::setValor('usa_emprestimo', ($_POST['usa_emprestimo'] ?? '') === '1' ? 1.0 : 0.0);
        $temCarro = ($_POST['tem_carro'] ?? '') === '1';
        if ($temCarro !== AjusteModel::usa('usa_carro')) {     // só na mudança: não traz de volta o que a pessoa removeu à mão
            AjusteModel::setValor('usa_carro', $temCarro ? 1.0 : 0.0);
            CategoriaModel::marcarCarro($temCarro);
        }
        // "adiantar" não troca o objetivo 2 (só quitar) por 1: só muda quem escolheu outra coisa
        $objetivo = ($_POST['objetivo'] ?? '') === '3' ? 3 : 1;
        if ($objetivo === 3 || (int) round(AjusteModel::valor('objetivo', 1.0)) === 3) {
            AjusteModel::setValor('objetivo', (float) $objetivo);
        }
        flash('success', t('Ajustes salvos.'));
        redirecionar('/ajustes?aberto=recursos#recursos', 303);
    }

    /* -------------------------------------------------------------- ícone do site */

    /** GET /icone: sem login, porque aparece também na tela de entrar. O endereço muda quando o ícone muda. */
    public function icone(): void
    {
        $f = SiteIcone::arquivo();
        if ($f === null) {
            redirecionar(SiteIcone::PADRAO, 302);
        }
        $etag = '"' . $f['hash'] . '"';
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . $f['tipo']);
        header('Content-Length: ' . strlen($f['dados']));
        echo $f['dados'];
    }

    public function salvarIcone(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $f = $_FILES['icone'] ?? null;
        $img = is_array($f) && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])
            ? LinkProduto::fotoValida((string) file_get_contents((string) $f['tmp_name']))
            : null;
        if ($img === null) {
            flash('error', t('Essa imagem não serve: use JPG, PNG ou WebP de até 3 MB.'));
            redirecionar('/ajustes?aberto=icone#icone', 303);
        }
        SiteIcone::salvar($img['tipo'], $img['dados']);
        flash('success', t('Ícone salvo.'));
        redirecionar('/ajustes?aberto=icone#icone', 303);
    }

    public function removerIcone(): void
    {
        Auth::requireLogin();
        csrfCheck();
        SiteIcone::remover();
        flash('success', t('Ícone padrão de volta.'));
        redirecionar('/ajustes?aberto=icone#icone', 303);
    }

    /* -------------------------------------------------------------- parâmetros e ponto de partida */

    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $grupo = ($_POST['grupo'] ?? '') === 'partida' ? 'partida' : 'parametros';
        $brutos = (array) ($_POST['valores'] ?? []);
        // os dados do empréstimo são da aba Empréstimo: aqui ficam fora da whitelist
        ['valores' => $valores, 'erro' => $errado] = self::lerValores($brutos, array_diff(AjusteModel::EDITAVEIS, AjusteModel::DO_EMPRESTIMO));
        if ($errado !== null) {
            $_SESSION['aj_erro'] = ['grupo' => $grupo, 'msg' => t('Confira o campo: {campo}.', ['campo' => self::rotulo($errado)]), 'campo' => $errado, 'valores' => $brutos];
            redirecionar('/ajustes?aberto=' . $grupo . '#' . $grupo, 303);
        }

        foreach ($valores as $chave => $v) {
            if ($chave === 'saldo_ini') {
                ContaModel::saldoInicialDaUnica($v);            // com várias contas, cada uma tem o seu, em Contas
                continue;
            }
            AjusteModel::setValor($chave, $v);                  // preserva `confirmado`
        }

        flash('success', $valores === [] ? t('Nada mudou.') : t('Ajustes salvos.'));
        redirecionar('/ajustes?aberto=' . $grupo . '#' . $grupo, 303);
    }

    /**
     * Confere os campos digitados, só os de $permitidas; os outros são ignorados.
     * @return array{valores: array<string, float>, erro: ?string} os valores prontos para gravar, ou a chave errada
     */
    public static function lerValores(array $brutos, array $permitidas): array
    {
        $valores = [];
        foreach ($brutos as $chave => $bruto) {
            $chave = (string) $chave;
            if (!in_array($chave, $permitidas, true)) {
                continue;                                       // fora da whitelist: ignorado
            }
            if ($chave === 'consignado_inicio' || $chave === 'saldo_devedor_mes' || $chave === 'meta_mes') {   // <input type="month">: "AAAA-MM" → AAAAMM; vazio = 0
                $bruto = is_string($bruto) ? trim($bruto) : '';
                if ($bruto !== '' && !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $bruto, $m)) {
                    return ['valores' => [], 'erro' => $chave];
                }
                $valores[$chave] = $bruto === '' ? 0.0 : (float) ((int) $m[1] * 100 + (int) $m[2]);
                continue;
            }
            if ($chave === 'saldo_devedor' && is_string($bruto) && trim($bruto) === '') {
                $valores[$chave] = 0.0;                         // em branco: volta a calcular o saldo pela parcela
                continue;
            }
            $v = parseMoedaBr(is_string($bruto) ? $bruto : '', $chave !== 'juros_mes');
            $ok = $v !== null && match ($chave) {
                'saldo_ini'  => true,                           // a conta pode começar negativa
                'n_parcelas' => $v >= 1 && abs($v - round($v)) < 1e-9,
                'parcela_mensal' => $v > 0,
                'juros_mes'  => $v >= 0 && $v < 100,
                default      => $v >= 0,
            };
            if (!$ok) {
                return ['valores' => [], 'erro' => $chave];
            }
            $valores[$chave] = $chave === 'juros_mes' ? round($v / 100, 6) : round($v, 4);   // o campo mostra %, o banco guarda a fração
        }
        return ['valores' => $valores, 'erro' => null];
    }

    /* -------------------------------------------------------------- aparelhos e senha */

    public function sairAparelhos(): void
    {
        Auth::requireLogin();
        csrfCheck();

        Auth::sairDeTodos((int) Auth::id());
        flash('success', t('Você saiu de todos os outros aparelhos.'));
        redirecionar('/ajustes?aberto=aparelhos#aparelhos', 303);
    }

    public function criarPessoa(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $str = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        $nome = mb_substr($str('nome'), 0, 100);
        $login = mb_substr($str('login'), 0, 100);
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';

        $erro = UsuarioModel::erroNovaConta($nome, $login, $senha);
        if ($erro !== null) {
            $_SESSION['aj_erro'] = ['grupo' => 'pessoas', 'msg' => $erro];
            redirecionar('/ajustes?aberto=pessoas#pessoas', 303);
        }
        UsuarioModel::criar($nome, $login, $senha);
        flash('success', t('Conta criada: {nome}', ['nome' => $nome]));
        redirecionar('/ajustes?aberto=pessoas#pessoas', 303);
    }

    /** A senha de outra pessoa da casa, para quem esqueceu. Pede a senha de quem está fazendo. */
    public function senhaDePessoa(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $alvo = (int) $id;
        $atual = is_string($_POST['senha_atual'] ?? null) ? $_POST['senha_atual'] : '';
        $nova = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        if (UsuarioModel::find($alvo) === null || $alvo === (int) Auth::id()) {
            redirecionar('/ajustes?aberto=pessoas#pessoas', 303);
        }
        $conferida = Auth::conferirSenhaAtual((int) Auth::id(), $atual);
        if ($conferida === 'bloqueado') {
            Auth::logout();
            renderErro(429, t('Muitas tentativas'), t('A senha atual foi digitada errada muitas vezes. Entre de novo em 10 minutos.'));
        }
        $erro = match (true) {
            $conferida !== 'ok'  => t('A senha atual não confere.'),
            mb_strlen($nova) < 8 => t('A senha precisa de pelo menos 8 caracteres.'),
            default              => null,
        };
        if ($erro !== null) {
            $_SESSION['aj_erro'] = ['grupo' => 'pessoas', 'msg' => $erro];
            redirecionar('/ajustes?aberto=pessoas#pessoas', 303);
        }
        UsuarioModel::trocarSenha($alvo, $nova);
        Auth::sairDeTodos($alvo);
        flash('success', t('Senha trocada.'));
        redirecionar('/ajustes?aberto=pessoas#pessoas', 303);
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
            renderErro(429, t('Muitas tentativas'), t('A senha atual foi digitada errada muitas vezes. Entre de novo em 10 minutos.'));
        }
        $erro = match (true) {
            $conferida !== 'ok'   => t('A senha atual não confere.'),
            mb_strlen($nova) < 6  => t('A nova senha precisa ter pelo menos 6 caracteres.'),
            $nova !== $repetir    => t('As duas senhas novas não são iguais.'),
            default               => null,
        };
        if ($erro !== null) {
            $_SESSION['aj_erro'] = ['grupo' => 'senha', 'msg' => $erro];
            redirecionar('/ajustes?aberto=senha#senha', 303);
        }

        UsuarioModel::trocarSenha((int) Auth::id(), $nova);
        Auth::sairDeTodos((int) Auth::id());                    // os outros aparelhos precisam da senha nova
        flash('success', t('Senha trocada.'));
        redirecionar('/ajustes', 303);
    }

    public static function rotulo(string $chave): string
    {
        return [
            'reserva_minima' => t('reserva mínima'), 'meta_final' => t('meta'), 'juros_mes' => t('juros ao mês'),
            'parcela_mensal' => t('parcela'), 'n_parcelas' => t('nº de parcelas'), 'consignado_inicio' => t('1ª parcela'), 'saldo_ini' => t('conta'),
            'meta_mes' => t('mês da meta'), 'poupanca_ini' => t('poupança'), 'investimentos_ini' => t('investimentos'), 'recebido_emprestimo' => t('crédito recebido'),
            'saldo_devedor' => t('saldo devedor'), 'saldo_devedor_mes' => t('mês do saldo'),
        ][$chave] ?? $chave;
    }
}
