<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\Services\LancarForm;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Lançar, extrato e editar.
 * Todo POST: requireLogin + csrfCheck; `volta` sempre por voltaSegura(); valor por parseMoedaBr()
 * (aceita -, − e –; grava abs()); data dentro de [primeiro dia de MesModel::primeiro(),
 * último dia de MesModel::ultimo()] (invariante 9) em store, update e desfazer.
 */
final class LancamentosController
{
    /** Filtros de grupo do extrato (`f=`), por classe da categoria. */
    private const FILTROS = ['entradas', 'essenciais', 'naoess', 'mov'];
    private const POR_PAGINA = 60;

    /* ================================================================== extrato */

    public function index(): void
    {
        Auth::requireLogin();

        $meses  = MesModel::todos();
        $mesCal = date('Y-m-01');
        $padrao = mesValido($mesCal, $meses, MesModel::ultimo() ?? $mesCal);
        $mes    = mesValido(self::get('mes'), $meses, $padrao);

        $usuarios = UsuarioModel::todos();
        $idsUsuarios = array_map('intval', array_column($usuarios, 'id'));

        $f    = self::get('f') ?? '';
        $f    = in_array($f, self::FILTROS, true) ? $f : '';
        $quem = (int) (self::get('quem') ?? 0);
        $quem = in_array($quem, $idsUsuarios, true) ? $quem : 0;
        $cat  = (int) (self::get('cat') ?? self::get('categoria') ?? 0);
        $categoria = $cat > 0 ? CategoriaModel::find($cat) : null;
        $cat  = $categoria ? $cat : 0;
        $q    = mb_substr(trim(self::get('q') ?? self::get('busca') ?? ''), 0, 80);
        $ordem = self::get('ordem') ?? '';
        $ordem = in_array($ordem, ['dia-desc', 'dia-asc', 'valor-desc', 'valor-asc', 'alfa'], true) ? $ordem : '';
        $limite = min(5000, max(self::POR_PAGINA, (int) (self::get('limite') ?? self::POR_PAGINA)));

        $filtro = [
            'classe'       => $f ?: null,
            'usuario_id'   => $quem ?: null,
            'categoria_id' => $cat ?: null,
            'q'            => $q,
            'ordem'        => $ordem,
        ];
        $linhas = LancamentoModel::listar($mes, $filtro + ['limite' => $limite]);
        $totais = LancamentoModel::totais($mes, $filtro);
        $filtrado = $f !== '' || $quem > 0 || $cat > 0 || $q !== '';

        // renda = a mesma palavra e o mesmo número do gráfico (entra do motor), nunca "entrou" (D2)
        $renda = null;
        if (!$filtrado) {
            $linhaPlano = MotorFinanceiro::linhaDoMes($mes);
            $renda = $linhaPlano !== null ? (float) $linhaPlano['entra'] : LancamentoModel::somaOperacional($mes, 1);
        }

        $situacao = 'PREVISAO';
        foreach ($meses as $m) {
            if ($m['mes'] === $mes) {
                $situacao = (string) $m['situacao'];
            }
        }

        $params = array_filter(['mes' => $mes, 'f' => $f, 'quem' => $quem ?: '', 'cat' => $cat ?: '', 'q' => $q, 'ordem' => $ordem], static fn($v) => $v !== '');
        $urlAtual = '/lancamentos?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
            . ($limite > self::POR_PAGINA ? '&limite=' . $limite : '');

        $categorias = CategoriaModel::ativas();

        View::render('lancamentos/index', [
            'pageTitle'  => t('extrato · {mes} · financeiro', ['mes' => monthLabel($mes)]),
            'mes'        => $mes,
            'meses'      => $meses,
            'situacao'   => $situacao,
            'estado'     => estadoMes($mes, $situacao),
            'hoje'       => date('Y-m-d'),
            'usuarios'   => $usuarios,
            'f'          => $f,
            'quem'       => $quem,
            'categoria'  => $categoria,
            'categorias' => $categorias,
            'q'          => $q,
            'ordem'      => $ordem,
            'params'     => $params,
            'limite'     => $limite,
            'linhas'     => $linhas,
            'totais'     => $totais,
            'filtrado'   => $filtrado,
            'renda'      => $renda,
            'urlAtual'   => $urlAtual,
            'form'       => LancarForm::dados() + ['modo' => 'pagina', 'pref' => 'pg', 'volta' => $urlAtual, 'topo' => false],
        ], 'extrato');
    }

    /* ================================================================== lançar (página) */

    /** GET /lancar — fallback sem JS do diálogo, e a volta dos erros/duplicados. */
    public function lancarForm(): void
    {
        Auth::requireLogin();

        $volta  = voltaSegura(self::get('volta'), '/');
        $antigo = self::tirarAntigo(null);
        if ($antigo === null) {
            // PAGAR sem JS: /lancar?categoria=9&valor=340,00&descricao=Faxineira&volta=/
            $pre = array_filter([
                'pagou'        => self::get('pagou') === 'cartao' ? 'cartao' : null,
                'categoria_id' => (int) (self::get('categoria') ?? 0) ?: null,
                'valor'        => self::get('valor'),
                'descricao'    => self::get('descricao'),
            ], static fn($v) => $v !== null && $v !== '');
            // PAGAR de um mês que ainda não começou: &data=AAAA-MM-DD → "outro dia" (cai no mês da conta)
            $dataPre = (string) (self::get('data') ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataPre) && self::validarData($dataPre) === null) {
                $pre['quando'] = 'outro';
                $pre['data'] = $dataPre;
            }
            $antigo = $pre !== [] ? $pre : null;
        }

        View::render('lancamentos/lancar', [
            'pageTitle'        => t('lançar · financeiro'),
            'semDialogoLancar' => true,
            'form'             => LancarForm::dados() + ['modo' => 'pagina', 'pref' => 'pg', 'volta' => $volta, 'antigo' => $antigo],
        ], '');
    }

    public function store(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(self::post('volta'), '');
        if (self::post('pagou') === 'cartao') {
            // "Pagou com: Cartão": vira uma compra no cartão (não é lançamento da conta; no plano só a fatura conta)
            $r = CartaoController::criarCompraDe($_POST);
            if ($r['erro'] !== null) {
                $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => $r['erro']];
                redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/cartao'), 303);
            }
            $destino = $volta !== '' ? $volta : '/cartao';
            flash('success', $r['aviso'], ['url' => '/cartao/compras/' . $r['id'] . '/excluir', 'label' => t('desfazer'),
                                           'campos' => ['volta' => $destino]]);
            redirecionar($destino);
        }
        [$erro, $d] = self::validar($_POST);
        if ($erro !== null) {
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => $erro];
            redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/'), 303);
        }

        if (self::post('confirmar_dup') !== '1' && ($dup = LancamentoModel::existeIgual($d['data'], $d['categoria_id'], $d['valor'])) !== null) {
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['duplicado' => [
                'descricao' => (string) $dup['descricao'],
                'quem'      => (string) $dup['usuario_nome'],
                'data'      => (string) $dup['data'],
            ]];
            redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/'), 303);
        }

        MesModel::ensureExists(substr($d['data'], 0, 8) . '01');   // dentro de [primeiro, último]: nunca cria mês novo
        $id = LancamentoModel::criar($d['data'], $d['descricao'], $d['categoria_id'], $d['valor'], (int) Auth::id());

        $destino = $volta !== '' ? $volta : '/lancamentos?mes=' . substr($d['data'], 0, 8) . '01';
        flash('success', self::avisoLancado($d['cat'], $d['valor'], $d['data']), [
            'url'    => '/lancamentos/' . $id . '/excluir',
            'label'  => t('desfazer'),
            'campos' => ['volta' => $destino],
        ]);
        redirecionar($destino);
    }

    /* ================================================================== editar */

    public function editForm(string $id): void
    {
        Auth::requireLogin();

        $l = LancamentoModel::find((int) $id);
        if (!$l) {
            renderErro(404, t('Lançamento não encontrado'), t('Pode ter sido excluído.'));
        }
        $volta = voltaSegura(self::get('volta'), '/lancamentos?mes=' . substr($l['data'], 0, 8) . '01');

        View::render('lancamentos/editar', [
            'pageTitle'  => t('editar · financeiro'),
            'lancamento' => $l,
            'volta'      => $volta,
            'form'       => LancarForm::dados() + [
                'modo'       => 'editar',
                'pref'       => 'ed',
                'volta'      => $volta,
                'lancamento' => $l,
                'antigo'     => self::tirarAntigo((int) $l['id']),
            ],
        ], 'extrato');
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $l = LancamentoModel::find((int) $id);
        if (!$l) {
            renderErro(404, t('Lançamento não encontrado'), t('Pode ter sido excluído.'));
        }
        $volta = voltaSegura(self::post('volta'), '/lancamentos?mes=' . substr($l['data'], 0, 8) . '01');

        [$erro, $d] = self::validar($_POST);
        if ($erro !== null) {
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => $erro, 'id' => (int) $l['id']];
            redirecionar('/lancamentos/' . (int) $l['id'] . '/editar?volta=' . rawurlencode($volta), 303);
        }

        MesModel::ensureExists(substr($d['data'], 0, 8) . '01');
        LancamentoModel::atualizar((int) $l['id'], $d['data'], $d['descricao'], $d['categoria_id'], $d['valor']);

        flash('success', t('{texto} · salvo', ['texto' => self::valorTexto($d['cat'], $d['valor'], true)]));
        redirecionar($volta);
    }

    public function destroy(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $l = LancamentoModel::find((int) $id);
        $mesL = $l ? substr($l['data'], 0, 8) . '01' : date('Y-m-01');
        $volta = voltaSegura(self::post('volta'), '/lancamentos?mes=' . $mesL);
        if (preg_match('#^/lancamentos/' . (int) $id . '/editar(?:[?\#]|$)#', $volta)) {
            $volta = '/lancamentos?mes=' . $mesL;       // a página de edição dele deixou de existir
        }
        if (!$l) {
            flash('warn', t('Esse lançamento já tinha sido excluído.'));
            redirecionar($volta);
        }

        LancamentoModel::excluir((int) $l['id']);
        $token = bin2hex(random_bytes(12));
        $_SESSION['lanc_desfazer'] = [
            'token' => $token,
            'linha' => [
                'id' => (int) $l['id'], 'data' => $l['data'], 'descricao' => $l['descricao'],
                'categoria_id' => (int) $l['categoria_id'], 'valor' => (float) $l['valor'],
                'usuario_id' => (int) $l['usuario_id'], 'criado_em' => $l['criado_em'],
            ],
        ];

        flash('success', t('excluído · {texto}', ['texto' => self::valorTexto($l, (float) $l['valor'], true)]), [
            'url'    => '/lancamentos/desfazer',
            'label'  => t('desfazer'),
            'campos' => ['volta' => $volta, 'token' => $token],
        ]);
        redirecionar($volta);
    }

    /** Desfaz a última exclusão desta sessão (o token vem no form do aviso). */
    public function desfazer(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(self::post('volta'), '/lancamentos');
        $d = $_SESSION['lanc_desfazer'] ?? null;
        $token = self::post('token') ?? '';
        if (!is_array($d) || !is_string($d['token'] ?? null) || !hash_equals($d['token'], $token)) {
            flash('warn', t('Não há nada para desfazer.'));
            redirecionar($volta);
        }
        unset($_SESSION['lanc_desfazer']);

        $linha = $d['linha'];
        $cat = CategoriaModel::find((int) $linha['categoria_id']);
        $erroData = self::validarData((string) $linha['data']);
        if ($cat === null || $erroData !== null) {
            flash('error', $erroData ?? t('Escolha uma categoria.'));
            redirecionar($volta);
        }

        MesModel::ensureExists(substr($linha['data'], 0, 8) . '01');
        LancamentoModel::restaurar($linha);
        flash('success', t('restaurado · {texto}', ['texto' => self::valorTexto($cat, (float) $linha['valor'], true)]));
        redirecionar($volta);
    }

    /* ================================================================== validação */

    /**
     * Data de lançamento aceitável? null = sim; senão a mensagem (`data?`, `data antes de set/26`,
     * `data depois de dez/27`). Limites: primeiro dia de MesModel::primeiro() e último dia de
     * MesModel::ultimo() — assim MesModel::ensureExists() nunca cria mês novo (invariantes 9 e 17).
     */
    public static function validarData(string $data): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return t('Escolha uma data válida.');
        }
        $primeiro = MesModel::primeiroDia();
        $ultimo   = MesModel::ultimo();
        if ($primeiro === null || $ultimo === null) {
            return t('Escolha uma data válida.');
        }
        if ($data < $primeiro) {
            return t('A data é antes do início, {data}. Escolha outra.', ['data' => diaMes($primeiro)]);
        }
        if ($data > date('Y-m-t', strtotime($ultimo))) {
            return t('A data é depois de {mes}. Escolha outra.', ['mes' => mesAno($ultimo)]);
        }
        return null;
    }

    /** `quando` (hoje|ontem|outro + data) → Y-m-d. Sem `quando`, usa `data` se vier (clientes antigos). */
    public static function dataDoQuando(array $in, ?string $hoje = null): string
    {
        $hoje ??= date('Y-m-d');
        $quando = is_string($in['quando'] ?? null) ? $in['quando'] : '';
        $data   = is_string($in['data'] ?? null) ? trim($in['data']) : '';
        return match ($quando) {
            'hoje'  => $hoje,
            'ontem' => date('Y-m-d', strtotime($hoje . ' -1 day')),
            'outro' => $data,
            default => $data !== '' ? $data : $hoje,
        };
    }

    /**
     * @return array{0:?string, 1:?array{data:string,descricao:string,categoria_id:int,valor:float,cat:array}}
     */
    private static function validar(array $in): array
    {
        $valor = parseMoedaBr(is_string($in['valor'] ?? null) ? $in['valor'] : null);
        $valor = $valor === null ? null : round(abs($valor), 2);     // "-50" / "−50" gravam 50 (o sinal vem da categoria)
        if ($valor === null || $valor <= 0 || $valor > valorMaximo()) {
            return [t('Digite um valor.'), null];
        }

        $categoriaId = (int) (is_string($in['categoria_id'] ?? null) ? $in['categoria_id'] : 0);
        $cat = $categoriaId > 0 ? CategoriaModel::find($categoriaId) : null;
        if ($cat === null) {
            return [t('Escolha uma categoria.'), null];
        }

        $data = self::dataDoQuando($in);
        if (($erro = self::validarData($data)) !== null) {
            return [$erro, null];
        }

        $descricao = is_string($in['descricao'] ?? null) ? $in['descricao'] : '';
        $descricao = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $descricao));
        if (mb_strlen($descricao) > 255) {
            return [t('Descrição muito longa. Use até 255 letras.'), null];
        }
        if ($descricao === '') {
            $descricao = (string) $cat['nome'];
        }

        return [null, [
            'data' => $data, 'descricao' => $descricao, 'categoria_id' => $categoriaId,
            'valor' => $valor, 'cat' => $cat,
        ]];
    }

    /* ================================================================== apoio */

    /** `Delivery −56,80` · `Salário +3.000,00` · `Guardar na poupança ⇄ 500,00` (sem o nome: só o valor). */
    private static function valorTexto(array $cat, float $valor, bool $comNome = false): string
    {
        $v = match (true) {
            (int) $cat['operacional'] !== 1 => '⇄ ' . num($valor),
            (int) $cat['sinal'] < 0         => MENOS . num($valor),
            default                         => '+' . num($valor),
        };
        return $comNome ? nomeCurto(['apelido' => $cat['apelido'] ?? null, 'nome' => $cat['nome'] ?? $cat['categoria_nome'] ?? '']) . ' ' . $v : $v;
    }

    /**
     * Aviso depois de lançar: `Delivery −56,80 · Ainda dá para gastar 343,20 da meta.`; mês fechado:
     * `Delivery −56,80 · set/26 fechado`; categoria sem previsão no mês: só `Salário +3.000,00`.
     */
    private static function avisoLancado(array $cat, float $valor, string $data): string
    {
        $txt = self::valorTexto($cat, $valor, true);
        $mes = substr($data, 0, 8) . '01';
        if (MesModel::situacao($mes) === 'REAL') {
            return t('{texto} · {mes} já está fechado.', ['mes' => mesAno($mes), 'texto' => $txt]);
        }
        if ((int) $cat['operacional'] === 1 && (int) $cat['sinal'] < 0) {
            $previsto = PrevisaoModel::valor((int) $cat['id'], $mes);
            if ($previsto > 0) {
                $resta = round($previsto - LancamentoModel::somaPorCategoria((int) $cat['id'], $mes), 2);
                $nome = nomeCurto(['apelido' => $cat['apelido'] ?? null, 'nome' => $cat['nome'] ?? $cat['categoria_nome'] ?? '']);
                if (MetaModel::valor((int) $cat['id']) !== null) {   // a previsão vem da meta; sem meta, é só uma estimativa
                    return $resta < -0.004
                        ? t('{texto} · Passou {valor} da meta de {nome}.', ['valor' => num(-$resta), 'nome' => $nome, 'texto' => $txt])
                        : t('{texto} · Ainda dá para gastar {valor} da meta.', ['valor' => num($resta), 'texto' => $txt]);
                }
                return $resta < -0.004
                    ? t('{texto} · Passou {valor} do previsto para {nome}.', ['valor' => num(-$resta), 'nome' => $nome, 'texto' => $txt])
                    : t('{texto} · Ainda restam {valor} do previsto.', ['valor' => num($resta), 'texto' => $txt]);
            }
        }
        return $txt;
    }

    /**
     * POST /lancamentos cujo token era de uma sessão que venceu e acabou de ser recriada
     * pelo cookie de lembrar (csrfCheck): nada é gravado; volta ao lançar preenchido.
     */
    public static function sessaoRenovada(): never
    {
        $volta = voltaSegura(self::post('volta'), '/');
        $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => t('A sessão foi renovada. Confira os dados e lance de novo.')];
        redirecionar('/lancar?volta=' . rawurlencode($volta), 303);
    }

    /** O que o usuário digitou, para a página voltar preenchida (erro ou duplicado). */
    private static function antigoDe(array $in): array
    {
        $s = static fn(string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr($in[$k], 0, $max) : '';
        $quando = $s('quando', 8);
        return [
            'pagou'        => $s('pagou', 8) === 'cartao' ? 'cartao' : 'conta',
            'parcelas'     => (string) (int) $s('parcelas', 3),
            'parcelas_outro' => (string) (int) $s('parcelas_outro', 3),
            'parcela_atual'  => (string) (int) $s('parcela_atual', 3),
            'valor'        => $s('valor', 40),
            'categoria_id' => (int) $s('categoria_id', 12),
            'descricao'    => $s('descricao', 255),
            'quando'       => in_array($quando, ['hoje', 'ontem', 'outro'], true) ? $quando : 'hoje',
            'data'         => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s('data', 10)) ? $s('data', 10) : '',
        ];
    }

    /** Lê (e consome) o formulário guardado na sessão; $id = só se for da edição deste lançamento. */
    private static function tirarAntigo(?int $id): ?array
    {
        $a = $_SESSION['lanc_antigo'] ?? null;
        if (!is_array($a) || (int) ($a['id'] ?? 0) !== (int) $id) {
            return null;
        }
        unset($_SESSION['lanc_antigo']);
        return $a;
    }

    private static function get(string $k): ?string
    {
        return is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
    }

    private static function post(string $k): ?string
    {
        return is_string($_POST[$k] ?? null) ? $_POST[$k] : null;
    }
}
