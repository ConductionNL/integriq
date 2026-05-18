<?php

/**
 * PHPStan bootstrap — registers OCP/OC namespace autoloaders so that
 * Nextcloud framework classes are resolvable during static analysis.
 */

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'OCP\\', 4) === 0) {
        $relative = str_replace('\\', '/', substr($class, 4));
        $path = __DIR__ . '/vendor/nextcloud/ocp/OCP/' . $relative . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    } elseif (strncmp($class, 'OC\\', 3) === 0) {
        // OC internal classes are not shipped with nextcloud/ocp.
        // Create a dummy stub so PHPStan can resolve inheritance chains.
        if (interface_exists($class, false) === false && class_exists($class, false) === false) {
            eval('namespace ' . implode('\\', array_slice(explode('\\', $class), 0, -1)) . '; interface ' . substr($class, strrpos($class, '\\') + 1) . ' {}');
        }
    }
});
