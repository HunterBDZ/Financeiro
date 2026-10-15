<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\CategoriaModel;

/**
 * Criar categoria (a partir do extrato). Escolhe-se entrada/saída e, para saída,
 * essencial/não essencial. Entrada cai em Entradas (+1); saída em Dia a dia (-1).
 */
final class CategoriaController
{
    public function criar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');

        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $nome));
        $tipo = ($_POST['tipo'] ?? '') === 'entrada' ? 'entrada' : 'saida';
        $essencial = ($_POST['essencial'] ?? '') === '1';

        if ($nome === '') {
            flash('error', 'nome da categoria?');
            redirecionar($volta . '#nova-categoria', 303);
        }
        if (mb_strlen($nome) > 100) {
            flash('error', 'nome longo');
            redirecionar($volta . '#nova-categoria', 303);
        }

        $id = CategoriaModel::criar($nome, $tipo, $essencial);
        if ($id === null) {
            flash('error', 'já existe uma categoria "' . $nome . '"');
            redirecionar($volta . '#nova-categoria', 303);
        }

        $rotulo = $tipo === 'entrada' ? 'entrada' : ($essencial ? 'gasto essencial' : 'gasto não essencial');
        flash('success', $nome . ' criada · ' . $rotulo);
        redirecionar($volta, 303);
    }

    /** POST /categorias/{id}/remover — arquiva (se tem histórico) ou apaga (se vazia). */
    public function remover(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        $cat   = CategoriaModel::find((int) $id);
        $nome  = $cat !== null ? (string) $cat['nome'] : '';

        $r = CategoriaModel::remover((int) $id);
        match ($r) {
            'apagada'   => flash('success', $nome . ' apagada'),
            'arquivada' => flash('success', $nome . ' arquivada · fora das listas, histórico mantido'),
            default     => flash('error', 'categoria não encontrada'),
        };
        redirecionar($volta . '#nova-categoria', 303);
    }
}
