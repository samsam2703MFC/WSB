<?php
// Ola flora — INTEGRACJE i SEO : ce que règle l'écran « Integracje i SEO » du back office
// (prive/ustawienia.php) et ce que lisent les points d'entrée publics (meta.php, seo.php,
// sitemap.php, strona.php).
//
//  · Les connexions vivent dans of_integrations (kind = meta | google | instagram) :
//    config_json en clair (identifiants, dates, compteurs), secret_enc CHIFFRÉ (jetons,
//    clé secrète de l'application Meta) — jamais renvoyé au navigateur, jamais au journal.
//  · Le référencement vit dans of_settings (skey = seo) : un seul JSON, lu d'un bloc.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const OF_JEZYKI = ['pl', 'en', 'de'];

/** Les pages publiques que le SEO règle : clé → fichier du site et nom lisible. */
const OF_SEO_STRONY = [
    'home'   => ['plik' => 'index.html', 'alias' => 'Ola flora - wesela v2.dc.html', 'nazwa' => 'Strona główna (wesela, oferta, o mnie, realizacje, kontakt)'],
    'oferta' => ['plik' => 'Ola flora - oferta live.dc.html', 'alias' => '', 'nazwa' => 'Oferta (wersja do wysłania)'],
];

/* ---- Chiffrement des secrets : clé tirée de la configuration du projet (jamais en base). */
function of_klucz(): string {
    $f = (string) (getenv('PROJET_CONFIG') ?: ($_SERVER['PROJET_CONFIG'] ?? ''));
    $c = $f !== '' && is_file($f) ? (include $f) : [];
    $zrodlo = (string) ($c['secret'] ?? '') !== '' ? (string) $c['secret'] : ((string) ($c['pass'] ?? '') . '|' . (string) ($c['dsn'] ?? ''));
    return hash('sha256', 'olaflora-integracje|' . $zrodlo, true);
}
function of_szyfruj(string $jawny): string {
    if (function_exists('sodium_crypto_secretbox')) {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'S1' . $n . sodium_crypto_secretbox($jawny, $n, of_klucz());
    }
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($jawny, 'aes-256-gcm', of_klucz(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'O1' . $iv . $tag . $c;
}
function of_odszyfruj(?string $b): string {
    if ($b === null || $b === '') return '';
    $typ = substr($b, 0, 2); $r = substr($b, 2);
    if ($typ === 'S1' && function_exists('sodium_crypto_secretbox_open')) {
        $n = substr($r, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $o = sodium_crypto_secretbox_open(substr($r, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $n, of_klucz());
        return $o === false ? '' : $o;
    }
    if ($typ === 'O1') {
        $o = openssl_decrypt(substr($r, 28), 'aes-256-gcm', of_klucz(), OPENSSL_RAW_DATA, substr($r, 0, 12), substr($r, 12, 16));
        return $o === false ? '' : $o;
    }
    return '';
}

/** Une connexion : ['config' => [...], 'sekrety' => [...], 'status', 'last_test_at', 'last_test_result']. */
function of_int(PDO $pdo, string $kind): array {
    $st = $pdo->prepare("SELECT * FROM of_integrations WHERE tenant_id = ? AND kind = ?");
    $st->execute([OF_TENANT, $kind]);
    $r = $st->fetch() ?: [];
    $sek = json_decode(of_odszyfruj($r['secret_enc'] ?? null), true);
    return ['config' => json_decode((string) ($r['config_json'] ?? ''), true) ?: [], 'sekrety' => is_array($sek) ? $sek : [],
            'status' => (string) ($r['status'] ?? 'nieskonfig'), 'last_test_at' => $r['last_test_at'] ?? null,
            'last_test_result' => (string) ($r['last_test_result'] ?? '')];
}
/** Écrit une connexion. $sekrety : seules les clés données changent (valeur '' = retirée). */
function of_int_zapisz(PDO $pdo, string $kind, array $config, ?array $sekrety = null, ?string $status = null, ?string $wynik = null): void {
    $stare = of_int($pdo, $kind);
    $sek = $stare['sekrety'];
    foreach ((array) $sekrety as $k => $v) { if ($v === '') unset($sek[$k]); else $sek[$k] = $v; }
    $cfg = array_merge($stare['config'], $config);
    $pdo->prepare("INSERT INTO of_integrations (tenant_id, kind, config_json, secret_enc, status, last_test_at, last_test_result)
                   VALUES (?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), secret_enc = VALUES(secret_enc),
                     status = COALESCE(?, status), last_test_at = IF(? IS NULL, last_test_at, NOW()), last_test_result = COALESCE(?, last_test_result)")
        ->execute([OF_TENANT, $kind, json_encode($cfg, JSON_UNESCAPED_UNICODE), $sek ? of_szyfruj(json_encode($sek)) : null,
                   $status ?? 'nieskonfig', $wynik !== null ? date('Y-m-d H:i:s') : null, $wynik,
                   $status, $wynik, $wynik !== null ? mb_substr($wynik, 0, 255) : null]);
}

/* ---- Réglages SEO : un seul JSON dans of_settings. */
function of_seo_domyslne(): array {
    $s = [];
    foreach (OF_SEO_STRONY as $k => $_) foreach (OF_JEZYKI as $j) $s['strony'][$k][$j] = ['title' => '', 'description' => '', 'og' => ''];
    $s['strony']['home']['pl'] = ['title' => 'Ola flora — florystka ślubna i dekoracje weselne', 'og' => '',
        'description' => 'Florystyka inspirowana naturą: bukiety ślubne, dekoracje sal i kościołów, kompozycje sezonowe. Pracownia Ola flora — zapytaj o termin.'];
    $s['strony']['home']['en'] = ['title' => 'Ola flora — wedding florist and event decorations', 'og' => '',
        'description' => 'Floristry inspired by nature: bridal bouquets, venue and church decorations, seasonal arrangements. Ask Ola flora about your date.'];
    $s['strony']['home']['de'] = ['title' => 'Ola flora — Hochzeitsfloristik und Dekorationen', 'og' => '',
        'description' => 'Floristik, inspiriert von der Natur: Brautsträuße, Saal- und Kirchendekoration, saisonale Gestecke. Fragen Sie Ola flora nach Ihrem Termin.'];
    $s['strony']['oferta']['pl'] = ['title' => 'Oferta — Ola flora', 'og' => '', 'description' => 'Oferta dekoracji ślubnych Ola flora: pakiety, wycena i warunki.'];
    $s['firma'] = ['nazwa' => 'Ola flora', 'opis' => '', 'ulica' => '', 'kod' => '', 'miasto' => '', 'region' => '', 'kraj' => 'PL',
        'telefon' => '', 'email' => '', 'godziny' => '', 'obszar' => '', 'ceny' => '$$', 'lat' => '', 'lng' => '', 'logo' => 'assets/ola-flora/logotyp-poziom-black.png'];
    $s['adres'] = '';           // l'adresse publique du site (https://…/olaflora/) ; vide = celle de la requête
    $s['frazy'] = [];           // [['fraza' => 'florystka ślubna Wrocław', 'strona' => 'home', 'lang' => 'pl', 'pozycja' => '', 'sprawdzono' => '']]
    $s['indeksuj'] = true;      // false = noindex (tant que le site n'est pas prêt)
    return $s;
}
function of_seo(PDO $pdo): array {
    $st = $pdo->prepare("SELECT svalue FROM of_settings WHERE tenant_id = ? AND brand_key = 0 AND skey = 'seo'");
    $st->execute([OF_TENANT]);
    $z = json_decode((string) ($st->fetchColumn() ?: ''), true);
    $d = of_seo_domyslne();
    if (!is_array($z)) return $d;
    foreach (OF_SEO_STRONY as $k => $_) foreach (OF_JEZYKI as $j)
        $d['strony'][$k][$j] = array_merge($d['strony'][$k][$j], array_intersect_key((array) ($z['strony'][$k][$j] ?? []), $d['strony'][$k][$j]));
    $d['firma'] = array_merge($d['firma'], array_intersect_key((array) ($z['firma'] ?? []), $d['firma']));
    $d['adres'] = (string) ($z['adres'] ?? '');
    $d['frazy'] = array_values(array_filter((array) ($z['frazy'] ?? []), 'is_array'));
    $d['indeksuj'] = (bool) ($z['indeksuj'] ?? true);
    return $d;
}
function of_seo_zapisz(PDO $pdo, array $s): void {
    $pdo->prepare("INSERT INTO of_settings (tenant_id, brand_id, skey, svalue, value_type, label) VALUES (?, NULL, 'seo', ?, 'json', 'SEO i pozycjonowanie')
                   ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
        ->execute([OF_TENANT, json_encode($s, JSON_UNESCAPED_UNICODE)]);
}

/** L'adresse publique du site, terminée par « / » : réglée, sinon déduite de la requête (dossier du site). */
function of_adres(array $seo): string {
    $a = trim((string) ($seo['adres'] ?? ''));
    if (preg_match('~^https?://[^\s]+$~', $a)) return rtrim($a, '/') . '/';
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('~[^A-Za-z0-9.:\-\[\]]~', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = preg_replace('~/_wsb/(prive/)?app/[^/]*$~', '/', $dir) ?? '/';
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
/** L'URL d'une page dans une langue (le polonais sans paramètre). */
function of_url_strony(string $adres, string $klucz, string $j): string {
    $plik = OF_SEO_STRONY[$klucz]['plik'] ?? 'index.html';
    $u = $adres . ($plik === 'index.html' ? '' : rawurlencode($plik));
    return $j === 'pl' ? $u : $u . '?lang=' . $j;
}

/* ---- Graph API de Meta. */
const OF_GRAPH = 'https://graph.facebook.com/v21.0/';
function of_graph(string $sciezka, array $p = [], string $metoda = 'GET'): array {
    $url = (getenv('OF_GRAPH_BASE') ?: OF_GRAPH) . ltrim($sciezka, '/');
    $ch = curl_init();
    if ($metoda === 'GET') $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($p);
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
    if ($metoda !== 'GET') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metoda); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p)); }
    $body = curl_exec($ch); $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = json_decode((string) $body, true);
    if (!is_array($j)) return ['_blad' => $err !== '' ? $err : 'HTTP ' . $kod];
    if (isset($j['error'])) $j['_blad'] = (string) ($j['error']['message'] ?? 'błąd Meta') . (isset($j['error']['code']) ? ' (' . $j['error']['code'] . ')' : '');
    return $j;
}

/**
 * Un lead Meta (Facebook ou Instagram) → une ZAPYTANIE, comme le formulaire du site :
 * of_clients (retrouvé par l'e-mail), of_inquiries (ZAP-AAAA-NNN, source meta-facebook |
 * meta-instagram), et TOUTES les réponses du formulaire dans of_inquiry_answers.
 * Jamais deux fois le même lead (qkey meta_lead). Rend le numéro, '' si déjà là, ou lève.
 */
function of_meta_lead_zapisz(PDO $pdo, array $lead, string $formularz = ''): string {
    $id = preg_replace('~\D~', '', (string) ($lead['id'] ?? ''));
    if ($id === '') throw new RuntimeException('lead sans id');
    $q = $pdo->prepare("SELECT 1 FROM of_inquiry_answers a JOIN of_inquiries i ON i.id = a.inquiry_id WHERE i.tenant_id = ? AND a.qkey = 'meta_lead' AND a.value_text = ? LIMIT 1");
    $q->execute([OF_TENANT, $id]);
    if ($q->fetchColumn()) return '';
    $pola = [];
    foreach ((array) ($lead['field_data'] ?? []) as $f) {
        $n = (string) ($f['name'] ?? ''); if ($n === '') continue;
        $pola[$n] = implode(', ', array_map('strval', (array) ($f['values'] ?? [])));
    }
    $znajdz = function (array $klucze) use ($pola): string {
        foreach ($pola as $n => $v) { $nl = mb_strtolower($n); foreach ($klucze as $k) if ($nl === $k || str_contains($nl, $k)) return trim($v); }
        return '';
    };
    $mail = strtolower($znajdz(['email', 'e-mail', 'mail']));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';
    $tel = preg_replace('/[^\d+]/', '', $znajdz(['phone_number', 'phone', 'telefon', 'tel'])) ?? '';
    $imie = $znajdz(['full_name', 'imię i nazwisko', 'imie']);
    if ($imie === '') $imie = trim($znajdz(['first_name']) . ' ' . $znajdz(['last_name']));
    $data = $znajdz(['data', 'date', 'termin']);
    $iso = null;
    if (preg_match('~(\d{4})-(\d{1,2})-(\d{1,2})~', $data, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) $iso = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    elseif (preg_match('~(\d{1,2})[./](\d{1,2})[./](\d{4})~', $data, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) $iso = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    $platforma = strtolower((string) ($lead['platform'] ?? '')) === 'ig' ? 'meta-instagram' : 'meta-facebook';

    $pdo->beginTransaction();
    try {
        $brand = (int) ($pdo->query("SELECT id FROM of_brands WHERE tenant_id = " . OF_TENANT . " AND slug = 'of'")->fetchColumn() ?: 0) ?: null;
        $cid = null;
        if ($mail !== '') { $c = $pdo->prepare("SELECT id FROM of_clients WHERE tenant_id = ? AND email = ? ORDER BY id DESC LIMIT 1"); $c->execute([OF_TENANT, $mail]); $cid = $c->fetchColumn() ?: null; }
        if (!$cid) {
            $pdo->prepare("INSERT INTO of_clients (tenant_id, brand_id, kind, display_name, email, phone, source) VALUES (?,?,'para',?,?,?,?)")
                ->execute([OF_TENANT, $brand, mb_substr($imie !== '' ? $imie : ($mail ?: ($tel ?: 'Lead ' . $id)), 0, 160), $mail ?: null, $tel ?: null, $platforma]);
            $cid = (int) $pdo->lastInsertId();
        }
        $no = of_numer($pdo, 'ZAP');
        $kiedy = !empty($lead['created_time']) && strtotime((string) $lead['created_time']) ? date('Y-m-d H:i:s', strtotime((string) $lead['created_time'])) : date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO of_inquiries (tenant_id, brand_id, number, client_id, source, status, received_at, event_date, venue_text, email, phone, user_agent)
                       VALUES (?,?,?,?,?,'nowe',?,?,?,?,?,?)")
            ->execute([OF_TENANT, $brand, $no, $cid, $platforma, $kiedy, $iso, mb_substr($znajdz(['sala', 'miejsce', 'venue', 'lokal']), 0, 190) ?: null,
                       $mail ?: null, $tel ?: null, mb_substr('Meta lead ' . $id . ($formularz !== '' ? ' · ' . $formularz : ''), 0, 255)]);
        $iid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO of_inquiry_answers (inquiry_id, qkey, question_text, value_text, value_json) VALUES (?,?,?,?,NULL)");
        $ins->execute([$iid, 'meta_lead', 'Lead Meta (id)', $id]);
        if ($formularz !== '') $ins->execute([$iid, 'meta_form', 'Formularz Meta', mb_substr($formularz, 0, 1000)]);
        foreach (['campaign_name' => 'Kampania', 'ad_name' => 'Reklama'] as $k => $lbl) if (!empty($lead[$k])) $ins->execute([$iid, 'meta_' . $k, $lbl, mb_substr((string) $lead[$k], 0, 1000)]);
        foreach ($pola as $n => $v) if ($v !== '') $ins->execute([$iid, mb_substr('meta:' . $n, 0, 30), mb_substr(str_replace('_', ' ', $n), 0, 190), mb_substr($v, 0, 4000)]);
        $pdo->commit();
        return $no;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Les réglages Google publics (rien de secret) : ce que la page charge APRÈS l'accord du visiteur. */
function of_google_publiczne(PDO $pdo): array {
    $g = of_int($pdo, 'google')['config'];
    $ok = fn($k, $re) => preg_match($re, (string) ($g[$k] ?? '')) ? (string) $g[$k] : '';
    return ['ga' => $ok('ga4', '~^G-[A-Z0-9]{4,16}$~'), 'ads' => $ok('ads_id', '~^AW-\d{6,14}$~'), 'adsLabel' => $ok('ads_label', '~^[A-Za-z0-9_\-]{4,40}$~')];
}
