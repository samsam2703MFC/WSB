<?php
// ============================================================================
//  siege_dane.php — les DONNÉES GÉNÉRALES de toutes les boutiques, pour le
//  superadmin BuddyCorp (siege/, onglet « Przegląd »).
//
//  Le siège lit chaque boutique comme elle se lit elle-même : son fichier de
//  configuration (/srv/webshops/boutiques/<b>/config.php, lisible par le
//  serveur web) donne l'accès à SA base. LECTURE SEULE — rien n'est écrit
//  dans une boutique d'ici. Une boutique muette (base injoignable) est dite,
//  jamais montrée à zéro.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/centrala.php';

/** Le dossier des boutiques : réglage, sinon deux crans au-dessus de la config du siège. */
function wsb_boutiques_dir(): string {
    $d = (string) (wsm_config()['siege']['boutiques_dir'] ?? '');
    if ($d === '') {
        $f = (string) getenv('WSM_CONFIG_FILE');
        $d = $f !== '' ? dirname($f, 2) : '';
    }
    return rtrim($d, '/');
}

function wsb_meta(string $conf): array {
    $out = [];
    foreach ((array) @file($conf, FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', (string) $l, $m)) $out[$m[1]] = $m[2];
    }
    return $out;
}

/** Les boutiques du serveur (sans le siège), avec leur version. */
function wsb_sklepy_serwera(): array {
    $dir = wsb_boutiques_dir();
    if ($dir === '' || !is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . '/*/boutique.conf') ?: [] as $conf) {
        $m = wsb_meta($conf);
        if (($m['TYPE'] ?? '') === 'siege') continue;
        $slug = basename(dirname($conf));
        $site = (string) @readlink(dirname($conf) . '/current');
        $wersja = $site !== '' ? basename(dirname($site)) : '';
        $commit = $wersja !== '' ? trim((string) @file_get_contents(dirname($site) . '/COMMIT')) : '';
        $out[$slug] = [
            'slug' => $slug, 'domena' => (string) ($m['DOMAINE'] ?? ''), 'alias' => (string) ($m['ALIAS'] ?? ''),
            'webshop' => (string) ($m['WEBSHOP'] ?? ''), 'od' => (string) ($m['CREEE'] ?? ''),
            'wersja' => $wersja, 'commit' => $commit, 'config' => dirname($conf) . '/config.php',
        ];
    }
    ksort($out);
    return $out;
}

/** La base d'une boutique, par SA configuration. */
function wsb_sklep_pdo(string $config): ?PDO {
    $c = @include $config;
    if (!is_array($c)) return null;
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_TIMEOUT => 5];
    try {
        if (($c['engine'] ?? '') === 'mysql') {
            $m = (array) ($c['mysql'] ?? []);
            return new PDO('mysql:host=' . ($m['host'] ?? 'localhost') . ';port=' . ($m['port'] ?? '3306')
                . ';dbname=' . ($m['name'] ?? '') . ';charset=utf8mb4', (string) ($m['user'] ?? ''), (string) ($m['pass'] ?? ''), $opts);
        }
        $p = (string) ($c['sqlite_path'] ?? '');
        return $p !== '' && is_file($p) ? new PDO('sqlite:' . $p, null, null, $opts) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Une valeur, ou null si la requête échoue (table absente…). */
function wsb_q(PDO $pdo, string $sql, array $p = []): mixed {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(); } catch (Throwable $e) { return null; }
}

/** Le volume payé d'un mois : commandes, brut, marchandises (grosze). */
function wsb_wolumen(PDO $pdo, string $ym): array {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $quand = $mysql ? 'COALESCE(paid_at, created_at)' : "COALESCE(NULLIF(paid_at, ''), created_at)";
    $base = "FROM wsm_orders WHERE status <> 'anulowane' AND payment_status = 'oplacone'";
    $r = wsb_q($pdo, "SELECT COUNT(*) n, COALESCE(SUM(total_gross),0) g, COALESCE(SUM(items_gross),0) t $base AND SUBSTR($quand, 1, 7) = ?", [$ym])
      ?? wsb_q($pdo, "SELECT COUNT(*) n, COALESCE(SUM(total_gross),0) g, COALESCE(SUM(items_gross),0) t $base AND SUBSTR(created_at, 1, 7) = ?", [$ym]);
    $f = wsb_q($pdo, "SELECT COALESCE(SUM(p.app_fee),0) f FROM wsm_payments p JOIN wsm_orders o ON o.id = p.order_id
                       WHERE p.provider = 'stripe' AND p.status = 'oplacone' AND o.status <> 'anulowane'
                         AND o.payment_status = 'oplacone' AND SUBSTR(o.created_at, 1, 7) = ?", [$ym]);
    return ['orders' => (int) ($r['n'] ?? 0), 'gross' => (int) ($r['g'] ?? 0), 'goods_gross' => (int) ($r['t'] ?? 0),
            'shipping_gross' => 0, 'fees_collected' => (int) ($f['f'] ?? 0), 'ok' => $r !== null];
}

/** Les conditions (loyer, taux, base) en vigueur un mois donné. */
function wsb_warunki(PDO $pdo, string $ym): array {
    $r = wsb_q($pdo, "SELECT rent_net, rate, basis, vat_rate FROM wsm_platform_terms WHERE from_ym <= ? ORDER BY from_ym DESC, id DESC LIMIT 1", [$ym]);
    return [
        'rent_net' => (int) ($r['rent_net'] ?? 0), 'rate' => isset($r['rate']) ? (float) $r['rate'] : 0.15,
        'basis' => (string) ($r['basis'] ?? 'brutto'), 'vat_rate' => isset($r['vat_rate']) ? (float) $r['vat_rate'] : 0.23,
        'ustalone' => $r !== null && $r !== false,
    ];
}

/** Le décompte d'un mois (commission + loyer), même règle que platform.php. */
function wsb_rozliczenie(array $v, array $t): array {
    $base = ($t['basis'] ?? 'brutto') === 'towar' ? (int) $v['goods_gross'] : (int) $v['gross'];
    $prow = (int) round($base * (float) $t['rate']);
    $netto = $prow + (int) $t['rent_net'];
    $brutto = $netto + (int) round($netto * (float) $t['vat_rate']);
    return ['prowizja' => $prow, 'czynsz' => (int) $t['rent_net'], 'netto' => $netto, 'brutto' => $brutto,
            'pobrane' => (int) ($v['fees_collected'] ?? 0), 'do_zaplaty' => max(0, $brutto - (int) ($v['fees_collected'] ?? 0))];
}

/** Toutes les données d'une boutique, pour le tableau. */
function wsb_dane_sklepu(array $s): array {
    $pdo = wsb_sklep_pdo($s['config']);
    if (!$pdo) return $s + ['dostepny' => false];
    $ym = date('Y-m');
    $ymPrev = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    $teraz = wsb_wolumen($pdo, $ym);
    $poprz = wsb_wolumen($pdo, $ymPrev);
    $t = wsb_warunki($pdo, $ym);
    $ost = wsb_q($pdo, "SELECT MAX(created_at) d FROM wsm_orders");
    $prod = wsb_q($pdo, "SELECT COUNT(*) n FROM wsm_products WHERE shop_visible = 1")
         ?? wsb_q($pdo, "SELECT COUNT(*) n FROM wsm_products");
    $kont = wsb_q($pdo, "SELECT COUNT(*) n FROM wsm_users WHERE act = 1 AND password_hash IS NOT NULL AND password_hash <> ''");
    $nazwa = wsb_q($pdo, "SELECT v AS val FROM wsm_shop_i18n WHERE k = 'seller.name' ORDER BY lang = 'pl' DESC LIMIT 1");
    $klucze = 0;
    try { $klucze = count(wsb_klucze($s['slug'], true)); } catch (Throwable $e) {}
    return $s + [
        'dostepny' => true, 'ym' => $ym, 'ymPrev' => $ymPrev,
        'teraz' => $teraz, 'poprz' => $poprz, 'warunki' => $t,
        'rozl_teraz' => wsb_rozliczenie($teraz, $t), 'rozl_poprz' => wsb_rozliczenie($poprz, wsb_warunki($pdo, $ymPrev)),
        'ostatnie' => (string) ($ost['d'] ?? ''), 'produkty' => (int) ($prod['n'] ?? 0), 'konta' => (int) ($kont['n'] ?? 0),
        'sprzedawca' => (string) ($nazwa['val'] ?? ''), 'klucze' => $klucze,
    ];
}
