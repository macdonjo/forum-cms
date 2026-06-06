<?php
class Router {
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void {
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(string $method, string $uri): void {
        $path = rtrim(parse_url($uri, PHP_URL_PATH), '/') ?: '/';

        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if ($routeMethod !== $method) continue;
            if (!preg_match($regex, $path, $m)) continue;
            $handler(array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            return;
        }

        http_response_code(404);
        render('404', ['title' => 'Page Not Found', 'description' => '']);
    }
}
