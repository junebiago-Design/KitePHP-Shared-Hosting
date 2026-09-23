<?php
// PSR-4 style autoloader (case-sensitive: folder names must match namespaces)
spl_autoload_register(function ($class) {
    $map = [
        'App\\'  => BASE_PATH . '/app/',
        'Core\\' => BASE_PATH . '/core/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require BASE_PATH . '/core/helpers.php';
