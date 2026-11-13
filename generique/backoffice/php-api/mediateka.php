<?php
// ============================================================================
//  mediateka.php — la médiathèque CLASSÉE, et ce qu'en tirent les réseaux.
//
//  media.php garde les fichiers, leur nom et leurs usages. Ici, ce qui sert
//  au marketing : CE QUE MONTRE la photo (une tablette, une praline, une
//  vitrine de l'atelier), POUR QUELLE OCCASION (Walentynki, Wielkanoc,
//  Mikołajki…), QUEL PRODUIT, et son TEXTE ALTERNATIF — celui que lisent les
//  lecteurs d'écran et Google Images.
//
//  Les catégories sont celles d'un chocolatier qui vend aussi aux
//  pâtissiers, pas celles d'une boulangerie : on cherche « une photo de
//  pralines pour la Saint-Valentin », pas « une photo de viennoiserie ».
//
//  LE JPEG DES RÉSEAUX. Les médias sont gardés en WebP (léger pour la
//  boutique). Instagram n'accepte que le JPEG, dans un cadre entre 4:5 et
//  1,91:1 ; Merchant Center et le catalogue Meta préfèrent le JPEG aussi.
//  wsm_media_jpg() fabrique cette version une fois, à côté (media/jpg/), et
//  la resert ensuite. L'original n'est jamais touché.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/media.php';

const WSM_MEDIATEKA_KATEGORIE = [
    'czekolada'  => 'Czekolada i kuwertura',
    'kakao'      => 'Kakao',
    'pralinki'   => 'Pralinki i bombonierki',
    'dekoracje'  => 'Dekoracje i posypki',
    'wypieki'    => 'Wypieki i desery (przepisy)',
    'skladniki'  => 'Mąki, cukry, składniki',
    'opakowania' => 'Opakowania i prezenty',
    'pracownia'  => 'Pracownia, za kulisami',
    'ludzie'     => 'Klienci i cukiernicy',
    'grafiki'    => 'Grafiki i promocje',
];

const WSM_MEDIATEKA_OKAZJE = [
    'caly_rok'     => 'Cały rok',
    'walentynki'   => 'Walentynki (14.02)',
    'dzien_kobiet' => 'Dzień Kobiet (8.03)',
    'wielkanoc'    => 'Wielkanoc',
    'dzien_matki'  => 'Dzień Matki (26.05)',
    'dzien_dziecka'=> 'Dzień Dziecka (1.06)',
    'lato'         => 'Lato',
    'szkola'       => 'Powrót do szkoły',
    'halloween'    => 'Halloween',
    'black_friday' => 'Black Friday',
    'mikolajki'    => 'Mikołajki (6.12)',
    'swieta'       => 'Boże Narodzenie',
];

function wsm_mediateka_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_media_meta')) wsm_apply_schema($pdo);
}

/** url → fiche (kategoria, okazja, product_id, alt, tagi). */
function wsm_mediateka_meta_all(PDO $pdo): array {
    $out = [];
    try {
        wsm_mediateka_ensure($pdo);
        foreach ($pdo->query("SELECT * FROM wsm_media_meta")->fetchAll() ?: [] as $r) $out[(string) $r['url']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** Étiquettes : minuscules, sans « # », dix au plus, trente signes chacune. */
function wsm_mediateka_tagi(string $raw): string {
    $t = [];
    foreach (preg_split('/[,;#\s]+/u', mb_strtolower($raw, 'UTF-8')) ?: [] as $x) {
        $x = trim(preg_replace('/[^\p{L}\p{N}_\-]+/u', '', $x) ?? '');
        if ($x !== '' && !in_array($x, $t, true)) $t[] = mb_substr($x, 0, 30);
        if (count($t) >= 10) break;
    }
    // La colonne fait 250 signes : on retire les dernières étiquettes plutôt
    // que de couper la dernière au milieu (ou de faire échouer l'écriture).
    while ($t && mb_strlen(implode(', ', $t)) > 250) array_pop($t);
    return implode(', ', $t);
}

/**
 * Classe un média. Une valeur hors des listes est refusée (renvoyée dans
 * l'erreur), pas enregistrée « à peu près ».
 *
 * @return string '' si enregistré, sinon la raison
 */
function wsm_mediateka_meta_set(PDO $pdo, string $url, array $in, string $actor = ''): string {
    if (!wsm_media_own_url($url)) return 'nieznany plik';
    wsm_mediateka_ensure($pdo);
    $kat = (string) ($in['kategoria'] ?? '');
    $ok  = (string) ($in['okazja'] ?? '');
    if ($kat !== '' && !isset(WSM_MEDIATEKA_KATEGORIE[$kat])) return 'nieznana kategoria';
    if ($ok !== '' && !isset(WSM_MEDIATEKA_OKAZJE[$ok])) return 'nieznana okazja';
    $pid = trim((string) ($in['product_id'] ?? ''));
    if ($pid !== '') {
        $st = $pdo->prepare("SELECT id FROM wsm_products WHERE id = ?");
        $st->execute([$pid]);
        if ($st->fetchColumn() === false) return 'nieznany produkt';
    }
    $alt = wsm_media_title_clean((string) ($in['alt'] ?? ''));
    $alt = mb_substr($alt, 0, 250);
    $tagi = wsm_mediateka_tagi((string) ($in['tagi'] ?? ''));
    $now = date('Y-m-d H:i:s');
    $vals = [$kat, $ok, $pid, $alt, $tagi, $now, mb_substr($actor, 0, 120), $url];
    $up = $pdo->prepare("UPDATE wsm_media_meta SET kategoria=?, okazja=?, product_id=?, alt=?, tagi=?, updated_at=?, updated_by=? WHERE url=?");
    $up->execute($vals);
    if ($up->rowCount() === 0) {
        // MySQL compte 0 ligne pour une mise à jour identique : la ligne peut
        // exister. On n'insère que si elle manque — et un échec se DIT.
        $ex = $pdo->prepare("SELECT 1 FROM wsm_media_meta WHERE url = ?");
        $ex->execute([$url]);
        $existe = $ex->fetchColumn() !== false;
        $ex->closeCursor();
        if (!$existe) {
            try {
                $pdo->prepare("INSERT INTO wsm_media_meta (kategoria, okazja, product_id, alt, tagi, updated_at, updated_by, url) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute($vals);
            } catch (Throwable $e) { return 'zapis nie powiódł się'; }
        }
    }
    return '';
}

/**
 * La médiathèque filtrée. Chaque entrée de wsm_media_list() reçoit sa fiche
 * ('meta') et ses usages ('usages').
 *
 * @param array $f  q, kategoria, okazja, produkt, bez_alt (bool), nieuzywane (bool)
 */
function wsm_mediateka_list(PDO $pdo, array $f = []): array {
    $meta = wsm_mediateka_meta_all($pdo);
    $uses = wsm_media_usages($pdo);
    $q = mb_strtolower(trim((string) ($f['q'] ?? '')), 'UTF-8');
    $out = [];
    foreach (wsm_media_list($pdo) as $m) {
        $mm = $meta[$m['url']] ?? ['kategoria' => '', 'okazja' => '', 'product_id' => '', 'alt' => '', 'tagi' => ''];
        $m['meta'] = $mm;
        $m['usages'] = $uses[$m['url']] ?? [];
        if (($f['kategoria'] ?? '') !== '' && $mm['kategoria'] !== $f['kategoria']) continue;
        if (($f['okazja'] ?? '') !== '' && $mm['okazja'] !== $f['okazja']) continue;
        if (($f['produkt'] ?? '') !== '' && $mm['product_id'] !== $f['produkt']) continue;
        if (!empty($f['bez_alt']) && trim((string) $mm['alt']) !== '') continue;
        if (!empty($f['nieuzywane']) && $m['usages']) continue;
        if ($q !== '') {
            $hay = mb_strtolower($m['title'] . ' ' . $mm['alt'] . ' ' . $mm['tagi'], 'UTF-8');
            if (!str_contains($hay, $q)) continue;
        }
        $out[] = $m;
    }
    return $out;
}

/**
 * La version JPEG d'un média, pour les réseaux et les flux.
 *
 *  · 'feed' : le même cadre, fond blanc (Merchant Center refuse les fonds
 *    transparents qui ressortent noirs) ;
 *  · 'ig'   : ramenée dans le cadre qu'Instagram accepte (entre 4:5 en
 *    hauteur et 1,91:1 en largeur), en ajoutant des marges blanches —
 *    jamais en coupant : une tablette dont on couperait le nom ne se vend pas.
 *
 * Fabriquée une fois, resservie tant que l'original n'a pas changé.
 * Renvoie le chemin relatif (« media/jpg/…-ig.jpg ») ou null.
 */
function wsm_media_jpg(string $url, string $tryb = 'feed'): ?string {
    if (!preg_match('#^media/([a-f0-9]{24})\.(webp|jpg|png)$#', $url, $m)) return null;
    if (!in_array($tryb, ['feed', 'ig'], true)) return null;
    $dir = wsm_media_dir();
    $src = $dir . '/' . $m[1] . '.' . $m[2];
    if (!is_file($src)) return null;
    $rel = 'media/jpg/' . $m[1] . '-' . $tryb . '.jpg';
    $dst = $dir . '/jpg/' . $m[1] . '-' . $tryb . '.jpg';
    if (is_file($dst) && filemtime($dst) >= filemtime($src)) return $rel;
    if (!extension_loaded('gd')) return null;
    $info = @getimagesize($src);
    $readers = wsm_media_readers();
    $read = $readers[(int) ($info[2] ?? 0)] ?? null;
    if (!$read) return null;
    $im = @$read($src);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $cw = $w; $ch = $h;
    if ($tryb === 'ig') {
        $r = $w / max(1, $h);
        if ($r < 0.8)       $cw = (int) ceil($h * 0.8);
        elseif ($r > 1.91)  $ch = (int) ceil($w / 1.91);
    }
    $out = imagecreatetruecolor($cw, $ch);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopy($out, $im, intdiv($cw - $w, 2), intdiv($ch - $h, 2), 0, 0, $w, $h);
    imagedestroy($im);
    if (!is_dir($dir . '/jpg') && !@mkdir($dir . '/jpg', 0775, true)) { imagedestroy($out); return null; }
    // Écrit à côté puis renommé : un robot de Meta qui lit pendant l'écriture
    // ne reçoit jamais une moitié d'image.
    $tmp = $dst . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @imagejpeg($out, $tmp, 88);
    imagedestroy($out);
    if (!$ok || !@rename($tmp, $dst)) { @unlink($tmp); return null; }
    @chmod($dst, 0644);
    return $rel;
}

/** Retire la fiche et les versions JPEG d'un média supprimé. */
function wsm_mediateka_forget(PDO $pdo, string $url): void {
    if (!preg_match('#^media/([a-f0-9]{24})\.#', $url, $m)) return;
    foreach (['feed', 'ig'] as $t) @unlink(wsm_media_dir() . '/jpg/' . $m[1] . '-' . $t . '.jpg');
    try { $pdo->prepare("DELETE FROM wsm_media_meta WHERE url = ?")->execute([$url]); } catch (Throwable $e) {}
}
