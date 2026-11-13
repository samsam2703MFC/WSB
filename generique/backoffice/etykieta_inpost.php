<?php
// ============================================================================
//  etykieta_inpost.php — l'étiquette du transporteur, relayée pour impression.
//
//  C'est CELLE-CI qui fait foi : le code-barres qu'InPost scanne. Notre
//  étiquette maison (etykieta_druk.php) ne la remplace pas et le dit.
//
//  Pourquoi un relais plutôt qu'un lien : ShipX exige le jeton porteur de
//  l'organisation. Le donner au navigateur reviendrait à le publier — n'importe
//  qui ouvrant les outils de développement pourrait créer des envois sur le
//  compte. La console va donc chercher le PDF avec son jeton, côté serveur, et
//  ne renvoie que le document.
//
//  Sortie en « inline » : le PDF s'ouvre dans le lecteur du navigateur, prêt à
//  imprimer. Personne ne veut télécharger un fichier pour ensuite le rouvrir.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/inpost.php';

$id = (int) ($_GET['id'] ?? 0);
$order = $id ? wsm_order_by_id($pdo, $id) : null;

/** Une page d'erreur lisible plutôt qu'un PDF vide ou un écran blanc. */
$stop = function (string $titre, string $detail, ?array $o = null) use ($id): void {
    http_response_code(409);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Etykieta InPost</title>'
       . '<link rel="stylesheet" href="_ds/mister-szoko/global.css">'
       . '<link rel="stylesheet" href="_ds/mister-szoko/brand.css">'
       . '<style>body{font-family:var(--font-sans);background:var(--bg-page-alt);margin:0;padding:40px 20px;'
       . 'color:var(--text-strong)}.box{max-width:560px;margin:0 auto;background:var(--surface-card);'
       . 'border:1px solid var(--border-subtle);border-radius:14px;padding:24px}'
       . 'h1{font-family:var(--font-display);font-size:20px;margin:0 0 10px}'
       . 'p{font-size:14px;line-height:1.6;color:var(--text-body)}'
       . 'code{font-family:var(--font-mono);font-size:12.5px}'
       . 'a{color:var(--brand)}</style></head><body><div class="box">'
       . '<h1>' . h($titre) . '</h1><p>' . $detail . '</p>'
       . ($o ? '<p><a href="zamowienia.php?id=' . (int) $o['id'] . '&amp;etykieta=1">'
             . 'Etykieta wewnętrzna (bez kodu kreskowego) →</a><br>'
             . '<a href="zamowienia.php?id=' . (int) $o['id'] . '">← Wróć do zamówienia</a></p>'
           : '<p><a href="zamowienia.php">← Zamówienia</a></p>')
       . '</div></body></html>';
    exit;
};

if (!$order) $stop('Nie znaleziono zamówienia', 'Numer <code>' . h((string) $id) . '</code> nie istnieje.');

$st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$st->execute([(int) $order['id']]);
$ship = $st->fetch() ?: [];

if (!$ship || trim((string) ($ship['shipment_id'] ?? '')) === '') {
    $stop('Przesyłka nie została jeszcze utworzona',
        'Etykieta przewoźnika powstaje dopiero po utworzeniu przesyłki w InPost. '
      . 'Na ekranie zamówienia użyj przycisku <b>Utwórz przesyłkę</b>, a potem wróć tutaj. '
      . 'Do czasu nadania możesz nakleić etykietę wewnętrzną — nie zastępuje ona listu przewozowego.',
        $order);
}

// A6 par défaut : le format d'une étiquette collée sur un colis. A4 pour les
// imprimantes ordinaires, qui n'ont pas de bac 10 × 15.
$size = ($_GET['format'] ?? '') === 'a4' ? 'A4' : 'A6';
[$pdf, $mime, $err] = wsm_inpost_label($ship, 'pdf', $size);

if ($err !== null) {
    $lien = (string) ($ship['label_url'] ?? '');
    $stop('Nie udało się pobrać etykiety',
        'InPost odpowiedział: <code>' . h($err) . '</code>.'
      . ($lien !== '' ? '<br><br>Zapisany wcześniej odnośnik: <a href="' . h($lien)
          . '" target="_blank" rel="noopener">otwórz etykietę ↗</a>' : '')
      . '<br><br>Jeśli integracja nie jest jeszcze skonfigurowana, uzupełnij dane w '
      . '<a href="ustawienia.php">Integracjach</a>, sekcja „inpost”.',
        $order);
}

wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Wydruk etykiety',
          'InPost ' . (string) ($ship['tracking_number'] ?? $ship['shipment_id']), 'Sieć');

$nom = 'etykieta-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $order['code']) . '.pdf';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $nom . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf;
