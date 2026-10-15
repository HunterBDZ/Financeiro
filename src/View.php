<?php
namespace Financeiro;

final class View
{
    /**
     * Renderiza uma tela dentro do layout.
     * O layout lê de $data: `pageTitle`, `semDialogoLancar` (true = sem o <dialog id="lancar">);
     * $active ∈ inicio | extrato | mes | futuro | ajustes (aba destacada).
     */
    public static function render(string $view, array $data = [], string $active = ''): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = __DIR__ . '/Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException("View não encontrada: {$view}");
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require __DIR__ . '/Views/layout/app.php';
    }

    /** Inclui um pedaço de view (partial) com as variáveis de $data, sem layout. */
    public static function partial(string $view, array $data = []): void
    {
        $__arquivo = __DIR__ . '/Views/' . $view . '.php';
        if (!is_file($__arquivo)) {
            throw new \RuntimeException("Partial não encontrado: {$view}");
        }
        (static function (string $__arquivo, array $__dados): void {
            extract($__dados, EXTR_SKIP);
            require $__arquivo;
        })($__arquivo, $data);
    }

    /** Página sem o layout (login). */
    public static function renderAuth(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require __DIR__ . '/Views/' . $view . '.php';
    }
}
