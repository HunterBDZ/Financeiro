<?php
namespace Financeiro;

final class Router
{
    private array $routes = [];

    /**
     * $handler: [Classe::class, 'metodo'] (a classe só é carregada no dispatch, então dá
     * para registrar rotas de controllers que ainda não existem) ou um callable (closure).
     */
    public function add(string $method, string $path, callable|array $handler): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
        ];
    }

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';   // o PHP descarta o corpo sozinho; os cabeçalhos saem iguais
        }

        // strtok, não parse_url: em "//host/x" o parse_url lê "host" como domínio e o
        // caminho vira "/x" (a página abria e os links relativos apontavam pra fora)
        $path = (string) strtok($uri, '?#');
        if ($path === '') {
            $path = '/';
        }
        if (str_starts_with($path, '//') || !str_starts_with($path, '/')) {
            renderErro(404, 'Página não encontrada', 'esse endereço não existe');
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                $handler = $this->resolve($route['handler']);
                if ($handler === null) {
                    // rota registrada, mas o controller/método ainda não existe (build em paralelo)
                    renderErro(404, 'Página não encontrada', 'esse endereço ainda não existe');
                }
                call_user_func_array($handler, array_values($params));
                return;
            }
        }

        renderErro(404, 'Página não encontrada', 'esse endereço não existe');
    }

    private function resolve(callable|array $handler): ?callable
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            [$class, $metodo] = $handler;
            if (!class_exists($class) || !method_exists($class, $metodo)) {
                return null;
            }
            return [new $class(), $metodo];
        }
        return is_callable($handler) ? $handler : null;
    }
}
