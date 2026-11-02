<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Icones;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MetaModel;
use Financeiro\View;

/**
 * Criar categoria (em Ajustes). Escolhe-se entrada/saída, para saída
 * essencial/não essencial, e o ícone (Icones). Entrada cai em Entradas (+1); saída em Dia a dia (-1).
 * Trocar o ícone de uma categoria: GET/POST /categorias/{id}/icone.
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

    /** GET /categorias/{id}/icone — escolher outro ícone para a categoria. */
    public function iconeForm(string $id): void
    {
        Auth::requireLogin();
        $cat = CategoriaModel::find((int) $id);
        if ($cat === null) {
            renderErro(404, t('Categoria não encontrada'), t('Essa categoria não existe mais.'));
        }
        $volta = voltaSegura(is_string($_GET['volta'] ?? null) ? $_GET['volta'] : null, self::AJUSTES);
        View::render('categorias/icone', [
            'pageTitle' => t('Ícone de {nome} · financeiro', ['nome' => nomeCurto($cat)]),
            'cat'       => $cat,
            'atual'     => Icones::daCategoria((int) $cat['id'], (string) $cat['nome'], (string) $cat['grupo']),
            'volta'     => $volta,
        ], 'ajustes');
    }

    /** POST /categorias/{id}/icone */
    public function salvarIcone(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, self::AJUSTES);
        $cat   = CategoriaModel::find((int) $id);
        $icone = is_string($_POST['icone'] ?? null) ? $_POST['icone'] : '';
        if ($cat === null) {
            flash('error', t('Categoria não encontrada.'));
            redirecionar($volta, 303);
        }
        if (!Icones::existe($icone)) {
            flash('error', t('Escolha um ícone.'));
            redirecionar('/categorias/' . (int) $id . '/icone?volta=' . rawurlencode($volta), 303);
        }
        CategoriaModel::setIcone((int) $id, $icone);
        flash('success', t('Ícone de {nome} trocado · {icone}', ['icone' => Icones::rotulo($icone), 'nome' => nomeCurto($cat)]));
        redirecionar($volta, 303);
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
