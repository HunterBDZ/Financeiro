<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Icones;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MetaModel;
use Financeiro\View;

/**
 * Categorias, em Ajustes. Criar: entrada ou gasto e, para gasto, essencial ou não, fixo ou que varia; e o ícone (Icones).
 * Editar tudo (GET/POST /categorias/{id}): as regras ficam em CategoriaModel::editar.
 */
final class CategoriaController
{
    private const AJUSTES = '/ajustes?aberto=categorias#categorias';

    public function criar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, self::AJUSTES);

        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $nome));
        $tipo = ($_POST['tipo'] ?? '') === 'entrada' ? 'entrada' : 'saida';
        $essencial = ($_POST['essencial'] ?? '') === '1';
        $icone = is_string($_POST['icone'] ?? null) && Icones::existe($_POST['icone']) ? $_POST['icone'] : null;
        // gasto: fixo (conta, com valor e dia de vencimento) ou que varia (com meta); os dois valores são opcionais
        $fixo = $tipo === 'saida' && ($_POST['valor_tipo'] ?? '') === 'fixo';
        $campoValor = $fixo ? 'valor_fixo' : 'meta';
        $brutoValor = is_string($_POST[$campoValor] ?? null) ? trim($_POST[$campoValor]) : '';
        $valorMes = $brutoValor === '' ? null : parseMoedaBr($brutoValor);
        $dia = (int) ($_POST['dia_vencimento'] ?? 0);

        if ($nome === '') {
            flash('error', t('Digite o nome da categoria.'));
            redirecionar($volta, 303);
        }
        if (mb_strlen($nome) > 100) {
            flash('error', t('Nome muito longo. Use até 100 letras.'));
            redirecionar($volta, 303);
        }

        if ($tipo === 'saida' && $brutoValor !== '' && ($valorMes === null || $valorMes < 0 || $valorMes > valorMaximo())) {
            flash('error', t('Valor inválido. Use só números, como 150 ou 150,90.'));
            redirecionar($volta, 303);
        }
        if ($fixo && $dia !== 0 && ($dia < 1 || $dia > 31)) {
            flash('error', t('O dia do vencimento vai de 1 a 31.'));
            redirecionar($volta, 303);
        }

        $id = CategoriaModel::criar($nome, $tipo, $essencial, $icone, $fixo);
        if ($id === null) {
            flash('error', t('Já existe uma categoria chamada {nome}.', ['nome' => $nome]));
            redirecionar($volta, 303);
        }
        if ($tipo === 'saida' && $valorMes !== null && $valorMes > 0.004) {
            MetaModel::definir($id, round($valorMes, 2));    // a meta é a previsão padrão de todo mês
        }
        if ($fixo && $dia >= 1) {
            ContaRecorrenteModel::upsert($id, $dia);
        }

        $rotulo = $tipo === 'entrada' ? t('entrada') : ($fixo ? t('conta fixa') : ($essencial ? t('gasto essencial') : t('gasto não essencial')));
        flash('success', t('{nome} criada · {tipo}', ['tipo' => $rotulo, 'nome' => $nome]));
        redirecionar($volta, 303);
    }

    /** GET /categorias/{id}: editar tudo da categoria. Erro de validação volta com o que foi digitado. */
    public function editarForm(string $id): void
    {
        Auth::requireLogin();
        $cat = ctype_digit($id) ? CategoriaModel::find((int) $id) : null;
        if ($cat === null) {
            renderErro(404, t('Categoria não encontrada'), t('Essa categoria não existe mais.'));
        }
        $erro = $_SESSION['cat_erro'] ?? null;
        unset($_SESSION['cat_erro']);
        if (!is_array($erro) || ($erro['id'] ?? 0) !== (int) $cat['id']) {
            $erro = null;
        }
        $conta = ContaRecorrenteModel::daCategoria((int) $cat['id']);
        View::render('categorias/editar', [
            'pageTitle'  => t('{nome} · financeiro', ['nome' => nomeCurto($cat)]),
            'cat'        => $cat,
            'icone'      => Icones::daCategoria((int) $cat['id'], (string) $cat['nome'], (string) $cat['grupo']),
            'dia'        => $conta !== null && (int) $conta['ativo'] === 1 ? (int) $conta['dia_vencimento'] : null,
            'doApp'      => CategoriaModel::doApp($cat),
            'historico'  => CategoriaModel::temHistorico((int) $cat['id']),
            'erros'      => $erro['erros'] ?? [],
            'valores'    => $erro['valores'] ?? null,
            'volta'      => voltaSegura(is_string($_GET['volta'] ?? null) ? $_GET['volta'] : null, self::AJUSTES),
        ], 'ajustes');
    }

    /** POST /categorias/{id} */
    public function salvar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, self::AJUSTES);
        $cat = ctype_digit($id) ? CategoriaModel::find((int) $id) : null;
        if ($cat === null) {
            flash('error', t('Categoria não encontrada.'));
            redirecionar($volta, 303);
        }
        $dados = [
            'nome' => $_POST['nome'] ?? '', 'apelido' => $_POST['apelido'] ?? '', 'icone' => $_POST['icone'] ?? '',
            'tipo' => $_POST['tipo'] ?? '', 'valor_tipo' => $_POST['valor_tipo'] ?? '', 'dia' => $_POST['dia_vencimento'] ?? '',
            'essencial' => ($_POST['essencial'] ?? '') === '1', 'ativo' => ($_POST['ativo'] ?? '') === '1',
        ];
        $erros = CategoriaModel::editar((int) $cat['id'], $dados);
        if ($erros !== []) {
            $_SESSION['cat_erro'] = ['id' => (int) $cat['id'], 'erros' => $erros, 'valores' => array_map(static fn($v) => is_string($v) || is_bool($v) ? $v : '', $dados)];
            redirecionar('/categorias/' . (int) $cat['id'] . '?volta=' . rawurlencode($volta), 303);
        }
        $novo = CategoriaModel::find((int) $cat['id']) ?? $cat;
        flash('success', t('{nome} salva', ['nome' => nomeCurto($novo)]));
        redirecionar($volta, 303);
    }

    /** GET /categorias/{id}/icone, o endereço antigo: o ícone agora se troca no editar. */
    public function iconeAntigo(string $id): void
    {
        Auth::requireLogin();
        $volta = is_string($_GET['volta'] ?? null) ? voltaSegura($_GET['volta'], '') : '';
        redirecionar('/categorias/' . (int) $id . ($volta !== '' ? '?volta=' . rawurlencode($volta) : ''), 301);
    }

    /** POST /categorias/{id}/remover — arquiva (se tem histórico) ou apaga (se vazia). */
    public function remover(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, self::AJUSTES);
        $cat   = CategoriaModel::find((int) $id);
        $nome  = $cat !== null ? nomeCategoria((string) $cat['nome']) : '';

        $r = CategoriaModel::remover((int) $id);
        match ($r) {
            'apagada'   => flash('success', t('{nome} apagada', ['nome' => $nome])),
            'arquivada' => flash('success', t('{nome} arquivada · fora das listas, histórico mantido', ['nome' => $nome])),
            default     => flash('error', t('Categoria não encontrada.')),
        };
        redirecionar($volta, 303);
    }
}
