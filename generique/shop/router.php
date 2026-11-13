<?php
// Routeur pour le serveur intégré de PHP (développement uniquement).
// En production c'est .htaccess qui fait ce travail — le serveur intégré
// ignore les .htaccess, d'où ce petit équivalent.
//   ./serve.sh   →   http://localhost:8091/
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) return false;  // fichier statique
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PATH_INFO'] = $path;
require __DIR__ . '/index.php';
