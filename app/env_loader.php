<?php
/**
 * Simple .env loader for the project
 * Reads .env from the root directory and defines constants if not already defined.
 */

if (!function_exists('load_env')) {
    function load_env(string $path): void {
        if (!file_exists($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            list($name, $value) = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            
            if (!defined($name)) {
                define($name, $value);
            }
            
            // Also put in $_ENV for good measure
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $value;
            }
        }
    }
}

// Auto-load .env from root
load_env(dirname(__DIR__) . '/.env');
