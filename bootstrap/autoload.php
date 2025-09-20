<?php
// PSR-4 style autoloader for the Game\* namespace mapped to /src
spl_autoload_register(function ($class) {
    $prefix  = 'Game\\';
    $baseDir = __DIR__ . '/../src/';

    // Only handle classes in the Game\ namespace
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    // Strip the namespace prefix and map to file
    $relative = substr($class, strlen($prefix));   // e.g., 'DB', 'Game', 'Util'
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
