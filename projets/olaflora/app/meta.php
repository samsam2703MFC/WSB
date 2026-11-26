<?php
// Public : l'adresse de rappel (webhook) des formulaires de prospects Meta — Facebook ET
// Instagram (Lead Ads). À coller dans l'application Meta, champ « leadgen » de la Page.
//
//  GET  : vérification de Meta (hub.challenge), avec le jeton choisi dans le back office.
//  POST : signature X-Hub-Signature-256 = HMAC du corps BRUT avec la clé secrète de
//         l'application, sinon 403. Le webhook ne porte que l'id du lead : on le relit
//         chez Meta (jeton de la Page) et il devient une zapytanie (of_meta_lead_zapisz).
declare(strict_types=1);
require __DIR__ . '/ustawienia_lib.php';
$pdo = of_pdo();
$meta = of_int($pdo, 'meta');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $tok = (string) ($meta['sekrety']['verify_token'] ?? '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $tok !== '' && hash_equals($tok, (string) ($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain; charset=utf-8');
        echo preg_replace('~[^A-Za-z0-9_\-]~', '', (string) ($_GET['hub_challenge'] ?? ''));
        exit;
    }
    http_response_code(403); exit('forbidden');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }

$raw = (string) file_get_contents('php://input');
$sekret = (string) ($meta['sekrety']['app_secret'] ?? '');
$podpis = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($sekret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $sekret), $podpis)) { http_response_code(403); exit('signature'); }
$in = json_decode($raw, true);

// Meta attend une réponse rapide : on répond, puis on traite.
http_response_code(200);
header('Content-Type: text/plain'); header('Content-Length: 2'); header('Connection: close');
echo 'OK';
while (ob_get_level() > 0) ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$token = (string) ($meta['sekrety']['page_token'] ?? '');
$nowe = 0; $bledy = [];
foreach ((array) ($in['entry'] ?? []) as $e) {
    foreach ((array) ($e['changes'] ?? []) as $c) {
        if (($c['field'] ?? '') !== 'leadgen') continue;
        $lid = preg_replace('~\D~', '', (string) ($c['value']['leadgen_id'] ?? ''));
        if ($lid === '' || $token === '') { $bledy[] = $token === '' ? 'brak tokenu strony' : 'brak id'; continue; }
        $lead = of_graph($lid, ['access_token' => $token, 'fields' => 'id,created_time,field_data,form_id,platform,ad_name,campaign_name']);
        if (isset($lead['_blad'])) { $bledy[] = $lead['_blad']; continue; }
        $form = '';
        if (!empty($lead['form_id'])) { $f = of_graph((string) $lead['form_id'], ['access_token' => $token, 'fields' => 'name']); $form = (string) ($f['name'] ?? ''); }
        try { if (of_meta_lead_zapisz($pdo, $lead, $form) !== '') $nowe++; }
        catch (Throwable $x) { $bledy[] = 'zapis'; error_log('olaflora meta: ' . $x->getMessage()); }
    }
}
of_int_zapisz($pdo, 'meta', ['ostatni_webhook' => date('Y-m-d H:i:s'), 'leady' => (int) ($meta['config']['leady'] ?? 0) + $nowe],
    null, $bledy ? 'blad' : 'ok', $bledy ? 'Webhook: ' . implode('; ', array_unique($bledy)) : ($nowe ? 'Webhook: ' . $nowe . ' nowe zapytanie' : null));
