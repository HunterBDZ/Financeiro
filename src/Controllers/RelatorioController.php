<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Pdf\RelatorioPdf;
use Financeiro\Services\RelatorioDados;
use Financeiro\View;

/** Aba Relatório: escolhe mensal ou anual, mostra a primeira página na tela e entrega o PDF completo. */
final class RelatorioController
{
    public function index(): void
    {
        Auth::requireLogin();
        [$tipo, $periodos, $relatorio] = self::relatorioPedido();
        View::render('relatorio/index', [
            'pageTitle' => t('relatório · financeiro'), 'tipo' => $tipo, 'periodos' => $periodos, 'r' => $relatorio,
        ], 'relatorio');
    }

    public function pdf(): void
    {
        Auth::requireLogin();
        [, , $relatorio] = self::relatorioPedido();
        if ($relatorio === null) {
            renderErro(404, t('Relatório não encontrado'), t('este período ainda não tem relatório'));
        }
        $doc = new RelatorioPdf($relatorio);
        $conteudo = $doc->gerar();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $doc->nomeArquivo() . '"');
        header('Content-Length: ' . strlen($conteudo));
        header('Cache-Control: private, no-store');
        echo $conteudo;
    }

    /** @return array{0:string, 1:array, 2:?array} */
    private static function relatorioPedido(): array
    {
        $tipo = ($_GET['tipo'] ?? '') === 'anual' ? 'anual' : 'mensal';
        $periodos = RelatorioDados::periodos();
        if ($tipo === 'anual') {
            $ano = (int) ($_GET['ano'] ?? 0);
            $ano = in_array($ano, $periodos['anos'], true) ? $ano : $periodos['anoPadrao'];
            return [$tipo, $periodos, $ano !== null ? RelatorioDados::anual($ano) : null];
        }
        $mes = (string) ($_GET['mes'] ?? '');
        $mes = in_array($mes, array_column($periodos['meses'], 'mes'), true) ? $mes : $periodos['mesPadrao'];
        return [$tipo, $periodos, $mes !== null ? RelatorioDados::mensal($mes) : null];
    }
}
