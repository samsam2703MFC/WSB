<?php
// ============================================================================
//  etykieta_dpd.php — l'étiquette DPD, relayée pour impression.
//
//  C'est CELLE-CI qui fait foi : le code-barres que DPD scanne. Notre
//  étiquette maison (etykieta_druk.php) ne la remplace pas, et le dit.
//
//  POURQUOI UN RELAIS PLUTÔT QU'UN LIEN. Le service de DPD exige le login et
//  le mot de passe du compte à chaque appel. Les donner au navigateur
//  reviendrait à les publier : n'importe qui ouvrant les outils de
//  développement pourrait expédier sur notre compte, à nos frais. La console
//  va donc chercher le PDF côté serveur et ne renvoie que le document.
//
//  Sortie en « inline » : le PDF s'ouvre dans le lecteur du navigateur, prêt à
//  imprimer. Personne ne veut télécharger un fichier pour ensuite le rouvrir.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/dpd.php';

$id = (int) ($_GET['id'] ?? 0);
$order = $id ? wsm_order_by_id($pdo, $id) : null;

/** Une page d'erreur lisible plutôt qu'un PDF vide ou un écran blanc. */
$stop = function (string $titre, string $detail, ?array $o = null) use ($id): void {
    http_response_code(409);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Etykieta DPD</title>'
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

// Le bon transporteur, et pas un autre. Sans ce contrôle, l'écran demanderait
// à DPD l'étiquette d'un colis InPost : DPD répondrait « inconnu », et l'on
// chercherait la panne du mauvais côté.
$carrier = wsm_ship_carrier($pdo, (string) $order['delivery_method']);
if ($carrier !== 'dpd') {
    $stop('To nie jest przesyłka DPD',
        'Zamówienie <code>' . h((string) $order['code']) . '</code> jedzie przewoźnikiem <code>'
      . h($carrier) . '</code>.'
      . ($carrier === 'inpost'
          ? ' <a href="etykieta_inpost.php?id=' . (int) $order['id'] . '">Etykieta InPost →</a>' : ''),
        $order);
}

$st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$st->execute([(int) $order['id']]);
$ship = $st->fetch() ?: [];

if (!$ship || trim((string) ($ship['tracking_number'] ?? '')) === '') {
    $stop('Przesyłka nie została jeszcze utworzona',
        'Etykieta przewoźnika powstaje dopiero po nadaniu przesyłki w DPD. '
      . 'Na ekranie <a href="wysylka.php">Wysyłka</a> użyj przycisku <b>Nadaj</b>, a potem wróć tutaj. '
      . 'Do czasu nadania możesz nakleić etykietę wewnętrzną — nie zastępuje ona listu przewozowego.',
        $order);
}

// A6 par défaut : le format d'une étiquette collée sur un colis. A4 pour les
// imprimantes ordinaires, qui n'ont pas de bac 10 × 15.
$size = ($_GET['format'] ?? '') === 'a4' ? 'A4' : 'A6';
[$pdf, $mime, $err] = wsm_dpd_label($order + ['shipment' => $ship], $size);

if ($err !== null) {
    $manque = wsm_dpd_manquants();
    $stop('Nie udało się pobrać etykiety',
        'DPD odpowiedział: <code>' . h($err) . '</code>.'
      . ($manque
          ? '<br><br>Brakuje: <b>' . h(implode(', ', $manque)) . '</b>. '
          . 'Uzupełnij w <a href="ustawienia.php">Integracjach</a>, sekcja „dpd”.'
          : '')
      // L'extension manquante ne se répare pas dans la console : on dit
      // clairement que c'est le serveur, pour ne pas envoyer quelqu'un
      // chercher un mot de passe qui ne manque pas.
      . (in_array('rozszerzenie PHP soap', $manque, true)
          ? '<br><br>Rozszerzenie <code>php-soap</code> instaluje się na serwerze — '
          . 'nie da się tego zrobić z konsoli.' : ''),
        $order);
}

wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Wydruk etykiety',
          'DPD ' . (string) ($ship['tracking_number'] ?? ''), 'Sieć');

$nom = 'etykieta-dpd-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $order['code']) . '.pdf';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $nom . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf;
