<?php
// Public : la demande envoyée par le formulaire (FormularzWizard, « Wysyłam »)
// → of_clients (retrouvé par l'e-mail), of_inquiries (ZAP-AAAA-NNN), of_inquiry_answers.
declare(strict_types=1);
require __DIR__ . '/lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { of_json(['error' => 'POST'], 405); exit; }
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) { of_json(['error' => 'json'], 400); exit; }
$v = is_array($in['vals'] ?? null) ? $in['vals'] : [];
$t = fn(string $k, int $max = 190): string => mb_substr(trim((string) ($v[$k] ?? '')), 0, $max);
$mail = strtolower($t('mail')); $tel = preg_replace('/[^\d+]/', '', $t('tel', 40)) ?? '';
if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';
if ($mail === '' && $tel === '' && $t('ona') === '' && $t('on') === '') { of_json(['error' => 'pusto'], 422); exit; }
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$pdo = of_pdo();
$st = $pdo->prepare("SELECT COUNT(*) FROM of_inquiries WHERE ip = ? AND received_at > NOW() - INTERVAL 1 HOUR");
$st->execute([$ip]);
if ((int) $st->fetchColumn() >= 10) { of_json(['error' => 'limit'], 429); exit; }

// La date : le formulaire écrit « 2027-6-5 » (sans zéros).
$iso = null;
if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t('data', 12), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) $iso = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
$multi = array_values(array_filter(array_map(fn($x) => mb_substr((string) $x, 0, 60), (array) ($in['multi'] ?? []))));
$acc = array_values(array_filter((array) ($in['accents'] ?? []), fn($x) => is_string($x) && preg_match('/^#[0-9a-fA-F]{6}$/', $x)));
$pal = mb_substr(trim((string) ($in['palette'] ?? '')), 0, 60);
$ona = $t('ona', 80); $on = $t('on', 80);
$who = trim($ona . ($ona !== '' && $on !== '' ? ' i ' : '') . $on);

$pdo->beginTransaction();
try {
    $brand = (int) ($pdo->query("SELECT id FROM of_brands WHERE tenant_id = " . OF_TENANT . " AND slug = 'of'")->fetchColumn() ?: 0) ?: null;
    $cid = null;
    if ($mail !== '') {
        $q = $pdo->prepare("SELECT id FROM of_clients WHERE tenant_id = ? AND email = ? ORDER BY id DESC LIMIT 1");
        $q->execute([OF_TENANT, $mail]); $cid = $q->fetchColumn() ?: null;
    }
    if (!$cid) {
        $pdo->prepare("INSERT INTO of_clients (tenant_id, brand_id, kind, display_name, partner1_name, partner2_name, email, phone, source)
                       VALUES (?,?,'para',?,?,?,?,?,'formularz')")
            ->execute([OF_TENANT, $brand, $who !== '' ? $who : ($mail ?: $tel), $ona ?: null, $on ?: null, $mail ?: null, $tel ?: null]);
        $cid = (int) $pdo->lastInsertId();
    }
    $no = of_numer($pdo, 'ZAP');
    $pdo->prepare("INSERT INTO of_inquiries (tenant_id, brand_id, number, client_id, source, status, received_at, event_date, venue_text, ceremony_text,
                   guests_range, tables_kind, head_table, style, palette_label, accents_json, colors_note, budget_range, extras_json, email, phone, ip, user_agent)
                   VALUES (?,?,?,?,'formularz','nowe',NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([OF_TENANT, $brand, $no, $cid, $iso, $t('sala') ?: null, $t('ceremonia', 60) ?: null, $t('goscie', 30) ?: null, $t('stoly', 30) ?: null,
                   $t('prezydialny', 40) ?: null, $t('styl', 40) ?: null, $pal ?: null, $acc ? json_encode($acc) : null, $t('kolory-txt', 2000) ?: null,
                   $t('budzet', 30) ?: null, $multi ? json_encode($multi, JSON_UNESCAPED_UNICODE) : null, $mail ?: null, $tel ?: null, $ip,
                   mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    $iid = (int) $pdo->lastInsertId();
    // Toutes les réponses, avec le texte de la question tel qu'il était.
    $pyt = [];
    foreach ($pdo->query("SELECT qkey, question FROM of_form_questions WHERE tenant_id = " . OF_TENANT) as $r) $pyt[$r['qkey']] = $r['question'];
    $odp = ['imiona' => $who, 'data' => $iso ?? $t('data', 12), 'miejsce' => $t('sala'), 'goscie' => $t('goscie'), 'stoly' => $t('stoly'),
            'prezydialny' => $t('prezydialny'), 'ceremonia' => $t('ceremonia'), 'dodatki' => $multi, 'styl' => $t('styl'),
            'kolory' => ['paleta' => $pal, 'akcenty' => $acc, 'uwagi' => $t('kolory-txt', 2000)], 'budzet' => $t('budzet'),
            'kontakt' => ['mail' => $mail, 'tel' => $tel]];
    $ins = $pdo->prepare("INSERT INTO of_inquiry_answers (inquiry_id, qkey, question_text, value_text, value_json) VALUES (?,?,?,?,?)");
    foreach ($odp as $k => $val) {
        if ($val === '' || $val === [] ) continue;
        $ins->execute([$iid, $k, $pyt[$k] ?? $k, is_array($val) ? null : $val, is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : null]);
    }
    $pdo->commit();
    of_json(['ok' => true, 'numer' => $no]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('olaflora zapytanie: ' . $e->getMessage());
    of_json(['error' => 'zapis'], 500);
}
