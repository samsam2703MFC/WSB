<?php
// Privé (mot de passe du projet, Apache) : toutes les clés.
require dirname(__DIR__) . '/stockage_lib.php';
st_servir([], [], (string) ($_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? 'prive'));
