<?php
// Public : relit en arrière-plan les derniers posts Instagram du site (une fois par heure
// au plus — le cache décide, un visiteur ne peut pas faire appeler Meta plus souvent).
// adapt.js l'appelle sans attendre la réponse quand strona.php dit « odswiez ».
declare(strict_types=1);
require __DIR__ . '/ustawienia_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { of_json(['error' => 'metoda'], 405); exit; }
header('Cache-Control: no-store');
try { $c = of_ig_odswiez(of_pdo()); of_json(['ok' => true, 'posty' => count((array) ($c['items'] ?? []))]); }
catch (Throwable $e) { error_log('olaflora instagram: ' . $e->getMessage()); of_json(['ok' => false], 500); }
