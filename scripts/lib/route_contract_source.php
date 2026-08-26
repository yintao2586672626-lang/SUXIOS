<?php
declare(strict_types=1);

if (!function_exists('suxi_read_registered_route_contract_source')) {
    function suxi_read_registered_route_contract_source(string $root): string
    {
        $routeRoot = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'route';
        $bootstrapPath = $routeRoot . DIRECTORY_SEPARATOR . 'app.php';
        $bootstrap = file_get_contents($bootstrapPath);
        if (!is_string($bootstrap)) {
            throw new RuntimeException("Unable to read route bootstrap: {$bootstrapPath}");
        }

        $seen = [];
        $expanded = preg_replace_callback(
            "/require __DIR__ \\. '\/domain\/([a-z0-9_]+\\.php)';/",
            static function (array $match) use ($routeRoot, &$seen): string {
                $relativePath = 'domain/' . $match[1];
                if (isset($seen[$relativePath])) {
                    throw new RuntimeException("Duplicate route domain manifest registration: {$relativePath}");
                }
                $seen[$relativePath] = true;
                $path = $routeRoot . DIRECTORY_SEPARATOR . 'domain' . DIRECTORY_SEPARATOR . $match[1];
                $source = file_get_contents($path);
                if (!is_string($source)) {
                    throw new RuntimeException("Unable to read route domain manifest: {$path}");
                }

                return $source;
            },
            $bootstrap
        );
        if (!is_string($expanded)) {
            throw new RuntimeException('Unable to expand registered route manifests');
        }

        return $expanded;
    }
}
