<?php
// ============================================================================
//  formularze.php — les formulaires publics (/f/<slug>) et ce qu'ils reçoivent.
//
//  Le formulaire de contact répond à « une question ». Ceux-ci servent à
//  VENDRE : une pâtisserie qui veut un tarif de gros, un chef qui veut
//  goûter trois couvertures avant d'en commander vingt kilos, une entreprise
//  qui cherche cent coffrets pour Noël, une inscription à un atelier. Chacun
//  pose ses propres questions ; la console les construit sans code.
//
//  MÊMES GARDE-FOUS QUE LE CONTACT, PAR LE MÊME CODE : champ piège, jeton
//  horodaté signé, plafond par adresse IP (compté dans la messagerie, où
//  chaque réponse arrive aussi). Un formulaire qui vend attire les robots
//  plus qu'un autre.
//
//  LA PREUVE DE L'ACCORD est le texte exact affiché à côté de la case,
//  recomposé par le serveur — jamais renvoyé par le navigateur. La case
//  newsletter, quand le formulaire la propose, passe par l'inscription
//  normale (wsm_mkt_subscribe) : double confirmation par e-mail comprise.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contact.php';

const WSM_FORM_TYPY = [
    'text' => 'Tekst', 'textarea' => 'Dłuższy tekst', 'email' => 'E-mail', 'tel' => 'Telefon',
    'number' => 'Liczba', 'select' => 'Lista wyboru', 'checkbox' => 'Pole wyboru (tak/nie)',
    'nip' => 'NIP (sprawdzany)', 'date' => 'Data',
];
const WSM_FORM_STATUSY = ['nowe' => 'Nowe', 'w_toku' => 'W toku', 'zamkniete' => 'Zamknięte', 'spam' => 'Spam'];
const WSM_FORM_MAX_POL = 20;

function wsm_forms_ensure(PDO $pdo): void {
    foreach (['wsm_forms', 'wsm_form_entries'] as $t) {
        if (!wsm_table_exists($pdo, $t)) { wsm_apply_schema($pdo); return; }
    }
}

/**
 * Les modèles, écrits pour un chocolatier qui vend aussi aux professionnels.
 * Un modèle n'est qu'un point de départ : tout se modifie ensuite.
 */
function wsm_form_szablony(): array {
    $imie  = ['id' => 'imie', 'typ' => 'text', 'etykieta' => 'Imię i nazwisko', 'wymagane' => true, 'opcje' => []];
    $email = ['id' => 'email', 'typ' => 'email', 'etykieta' => 'E-mail', 'wymagane' => true, 'opcje' => []];
    $tel   = ['id' => 'telefon', 'typ' => 'tel', 'etykieta' => 'Telefon', 'wymagane' => false, 'opcje' => []];
    $firma = ['id' => 'firma', 'typ' => 'text', 'etykieta' => 'Nazwa firmy', 'wymagane' => true, 'opcje' => []];
    $nip   = ['id' => 'nip', 'typ' => 'nip', 'etykieta' => 'NIP', 'wymagane' => true, 'opcje' => []];
    return [
        'hurt' => [
            'nazwa' => 'Współpraca hurtowa (B2B)', 'slug' => 'hurt',
            'wstep' => 'Prowadzisz cukiernię, piekarnię, kawiarnię albo sklep? Zostaw dane — przygotujemy ofertę hurtową na czekoladę, kuwerturę i produkty cukiernicze, z cenami od wagi zamówienia.',
            'podziekowanie' => 'Dziękujemy! Oferta hurtowa trafi na Twój e-mail w ciągu jednego dnia roboczego.',
            'pola' => [$firma, $nip, $imie, $email, $tel,
                ['id' => 'rodzaj', 'typ' => 'select', 'etykieta' => 'Rodzaj działalności', 'wymagane' => true,
                 'opcje' => ['Cukiernia', 'Piekarnia', 'Kawiarnia / restauracja', 'Sklep', 'Hotel / catering', 'Inna']],
                ['id' => 'ilosc', 'typ' => 'select', 'etykieta' => 'Szacowane zakupy miesięcznie', 'wymagane' => false,
                 'opcje' => ['do 20 kg', '20–100 kg', '100–500 kg', 'ponad 500 kg']],
                ['id' => 'wiadomosc', 'typ' => 'textarea', 'etykieta' => 'Czego potrzebujesz?', 'wymagane' => false, 'opcje' => []]],
        ],
        'probki' => [
            'nazwa' => 'Zamów próbki czekolady', 'slug' => 'probki',
            'wstep' => 'Dla cukierni i pracowni: wybierz czekolady do przetestowania, a wyślemy próbki. Zanim zamówisz kilogramy — sprawdź, jak pracują u Ciebie.',
            'podziekowanie' => 'Dziękujemy! Potwierdzimy wysyłkę próbek e-mailem.',
            'pola' => [$firma, $nip, $imie, $email, $tel,
                ['id' => 'adres', 'typ' => 'textarea', 'etykieta' => 'Adres dostawy próbek', 'wymagane' => true, 'opcje' => []],
                ['id' => 'produkty', 'typ' => 'textarea', 'etykieta' => 'Które czekolady chcesz przetestować?', 'wymagane' => true, 'opcje' => []]],
        ],
        'warsztaty' => [
            'nazwa' => 'Zapisy na warsztaty czekoladowe', 'slug' => 'warsztaty',
            'wstep' => 'Temperowanie, pralinki, tabliczki z dodatkami — praktyczne warsztaty w małej grupie. Zapisz się, a potwierdzimy miejsce i szczegóły.',
            'podziekowanie' => 'Dziękujemy za zapis! Potwierdzenie miejsca wyślemy e-mailem.',
            'pola' => [$imie, $email, $tel,
                ['id' => 'termin', 'typ' => 'select', 'etykieta' => 'Termin', 'wymagane' => true, 'opcje' => ['Do ustalenia']],
                ['id' => 'osoby', 'typ' => 'number', 'etykieta' => 'Liczba osób', 'wymagane' => true, 'opcje' => []],
                ['id' => 'wiadomosc', 'typ' => 'textarea', 'etykieta' => 'Uwagi (np. alergie)', 'wymagane' => false, 'opcje' => []]],
        ],
        'prezenty' => [
            'nazwa' => 'Prezenty firmowe — zapytanie', 'slug' => 'prezenty-firmowe',
            'wstep' => 'Czekoladowe upominki dla klientów i pracowników: na święta, jubileusz, konferencję. Napisz, ile i na kiedy — przygotujemy propozycję.',
            'podziekowanie' => 'Dziękujemy! Propozycję wyślemy w ciągu dwóch dni roboczych.',
            'pola' => [$firma,
                ['id' => 'nip', 'typ' => 'nip', 'etykieta' => 'NIP', 'wymagane' => false, 'opcje' => []],
                $imie, $email, $tel,
                ['id' => 'ilosc', 'typ' => 'number', 'etykieta' => 'Liczba upominków', 'wymagane' => true, 'opcje' => []],
                ['id' => 'termin', 'typ' => 'date', 'etykieta' => 'Na kiedy?', 'wymagane' => false, 'opcje' => []],
                ['id' => 'budzet', 'typ' => 'text', 'etykieta' => 'Budżet na upominek (orientacyjnie)', 'wymagane' => false, 'opcje' => []],
                ['id' => 'wiadomosc', 'typ' => 'textarea', 'etykieta' => 'Szczegóły', 'wymagane' => false, 'opcje' => []]],
        ],
    ];
}

function wsm_form_slug(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return substr(trim($s, '-'), 0, 60);
}

/**
 * Nettoie la définition des champs envoyée par l'écran.
 * @return array{0:list<array>, 1:list<string>} [champs, erreurs]
 */
function wsm_form_pola_clean(array $raw): array {
    $out = []; $err = []; $ids = [];
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        $et = trim(preg_replace('/\s+/u', ' ', (string) ($r['etykieta'] ?? '')) ?? '');
        if ($et === '') continue;                                   // ligne vide : retirée
        $typ = (string) ($r['typ'] ?? 'text');
        if (!isset(WSM_FORM_TYPY[$typ])) $typ = 'text';
        $id = wsm_form_slug((string) ($r['id'] ?? '')) ?: wsm_form_slug($et);
        $id = str_replace('-', '_', substr($id, 0, 30));
        if ($id === '' || in_array($id, ['zgoda', 'zgoda_nl', 'firma_www', '_ts', '_csrf'], true)) $id = 'pole' . (count($out) + 1);
        $base = $id; $n = 2;
        while (isset($ids[$id])) $id = $base . '_' . $n++;
        $ids[$id] = true;
        $opcje = [];
        if ($typ === 'select') {
            $src = is_array($r['opcje'] ?? null) ? $r['opcje'] : preg_split('/[|\n]+/u', (string) ($r['opcje'] ?? ''));
            foreach ($src ?: [] as $o) { $o = trim((string) $o); if ($o !== '' && !in_array($o, $opcje, true)) $opcje[] = mb_substr($o, 0, 80); }
            if (!$opcje) $err[] = '„' . $et . '”: lista wyboru potrzebuje opcji (rozdziel je znakiem |).';
        }
        $out[] = ['id' => $id, 'typ' => $typ, 'etykieta' => mb_substr($et, 0, 120),
                  'wymagane' => !empty($r['wymagane']), 'opcje' => array_slice($opcje, 0, 30)];
        if (count($out) >= WSM_FORM_MAX_POL) break;
    }
    if (!$out) $err[] = 'Formularz potrzebuje co najmniej jednego pola.';
    $emails = array_filter($out, fn($p) => $p['typ'] === 'email');
    if (!$emails) $err[] = 'Dodaj pole typu „E-mail” — bez niego nie ma jak odpowiedzieć.';
    return [$out, $err];
}

/** @return array{0:int, 1:list<string>} */
function wsm_form_save(PDO $pdo, ?int $id, array $in): array {
    wsm_forms_ensure($pdo);
    $err = [];
    $nazwa = trim((string) ($in['nazwa'] ?? ''));
    if ($nazwa === '') $err[] = 'Podaj nazwę formularza.';
    $slug = wsm_form_slug((string) ($in['slug'] ?? '')) ?: wsm_form_slug($nazwa);
    if ($slug === '') $err[] = 'Podaj adres (np. hurt).';
    $st = $pdo->prepare("SELECT id FROM wsm_forms WHERE slug = ?" . ($id ? " AND id <> ?" : ''));
    $st->execute($id ? [$slug, $id] : [$slug]);
    if ($st->fetchColumn() !== false) $err[] = 'Adres /f/' . $slug . ' jest już zajęty.';
    [$pola, $e2] = wsm_form_pola_clean((array) ($in['pola'] ?? []));
    $err = array_merge($err, $e2);
    $powiadom = strtolower(trim((string) ($in['powiadom'] ?? '')));
    if ($powiadom !== '' && (!filter_var($powiadom, FILTER_VALIDATE_EMAIL) || strlen($powiadom) > 200)) $err[] = 'Adres powiadomień jest nieprawidłowy (do 200 znaków).';
    if ($err) return [$id ?? 0, $err];
    $vals = [$slug, mb_substr($nazwa, 0, 120), trim((string) ($in['wstep'] ?? '')),
             json_encode($pola, JSON_UNESCAPED_UNICODE), mb_substr(trim((string) ($in['podziekowanie'] ?? '')), 0, 500),
             $powiadom, !empty($in['newsletter']) ? 1 : 0, !empty($in['aktywny']) ? 1 : 0, date('Y-m-d H:i:s')];
    if ($id) {
        $pdo->prepare("UPDATE wsm_forms SET slug=?, nazwa=?, wstep=?, pola=?, podziekowanie=?, powiadom=?, newsletter=?, aktywny=?, updated_at=? WHERE id=?")
            ->execute(array_merge($vals, [$id]));
        return [$id, []];
    }
    $pdo->prepare("INSERT INTO wsm_forms (slug, nazwa, wstep, pola, podziekowanie, powiadom, newsletter, aktywny, updated_at, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($vals, [date('Y-m-d H:i:s')]));
    return [(int) $pdo->lastInsertId(), []];
}

/** Crée un formulaire depuis un modèle ; le slug libre le plus proche si le sien est pris. */
function wsm_form_from_szablon(PDO $pdo, string $kod): array {
    $t = wsm_form_szablony()[$kod] ?? null;
    if (!$t) return [0, ['Nieznany szablon.']];
    wsm_forms_ensure($pdo);
    $slug = $t['slug']; $n = 2;
    $st = $pdo->prepare("SELECT 1 FROM wsm_forms WHERE slug = ?");
    while (true) { $st->execute([$slug]); if ($st->fetchColumn() === false) break; $slug = $t['slug'] . '-' . $n++; }
    return wsm_form_save($pdo, null, ['nazwa' => $t['nazwa'], 'slug' => $slug, 'wstep' => $t['wstep'], 'pola' => $t['pola'],
                                      'podziekowanie' => $t['podziekowanie'], 'newsletter' => 1, 'aktywny' => 0]);
}

function wsm_form_hydrate(array $r): array {
    $r['pola_list'] = json_decode((string) $r['pola'], true) ?: [];
    return $r;
}

function wsm_form_get(PDO $pdo, int $id): ?array {
    wsm_forms_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_forms WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? wsm_form_hydrate($r) : null;
}

function wsm_form_by_slug(PDO $pdo, string $slug, bool $actifSeul = true): ?array {
    try {
        wsm_forms_ensure($pdo);
        $st = $pdo->prepare("SELECT * FROM wsm_forms WHERE slug = ?" . ($actifSeul ? " AND aktywny = 1" : ''));
        $st->execute([$slug]);
        $r = $st->fetch();
        return $r ? wsm_form_hydrate($r) : null;
    } catch (Throwable $e) { return null; }
}

/** Les formulaires, avec le nombre de réponses nouvelles et totales. */
function wsm_forms_list(PDO $pdo): array {
    wsm_forms_ensure($pdo);
    $rows = $pdo->query("SELECT f.*,
                                (SELECT COUNT(*) FROM wsm_form_entries e WHERE e.form_id = f.id AND e.status = 'nowe') AS nowe,
                                (SELECT COUNT(*) FROM wsm_form_entries e WHERE e.form_id = f.id AND e.status <> 'spam') AS razem
                           FROM wsm_forms f ORDER BY f.aktywny DESC, f.nazwa")->fetchAll();
    return array_map('wsm_form_hydrate', $rows);
}

/** Les formulaires actifs, pour le plan du site. */
function wsm_forms_sitemap(PDO $pdo): array {
    try {
        wsm_forms_ensure($pdo);
        return array_map('strval', $pdo->query("SELECT slug FROM wsm_forms WHERE aktywny = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

function wsm_form_delete(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM wsm_form_entries WHERE form_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_forms WHERE id = ?")->execute([$id]);
}

/**
 * Valide et enregistre une réponse.
 *
 * @param array $in      $_POST du formulaire public
 * @param array $teksty  ['zgoda' => texte exact de la case RODO, 'nl' => texte exact de la case newsletter]
 * @return array{0:?int, 1:array} [id, erreurs champ => message] — '_bot' : refus silencieux
 */
function wsm_form_submit(PDO $pdo, array $form, array $in, array $teksty, string $lang = 'pl'): array {
    require_once __DIR__ . '/mail.php';
    if (trim((string) ($in['firma_www'] ?? '')) !== '') return [null, ['_bot' => 'piege']];
    [$okStamp, $pourquoi] = wsm_contact_stamp_ok((string) ($in['_ts'] ?? ''));
    if (!$okStamp) return [null, ['_bot' => $pourquoi]];
    $ip = wsm_contact_ip();
    if (wsm_contact_trop($pdo, $ip)) return [null, ['_limit' => 'zbyt wiele zgłoszeń — spróbuj za godzinę']];

    $e = []; $dane = []; $email = '';
    foreach ($form['pola_list'] as $p) {
        $id = (string) $p['id'];
        $raw = $in['p'][$id] ?? '';
        $v = is_array($raw) ? '' : trim(str_replace(["\r\n", "\r"], "\n", (string) $raw));
        $req = !empty($p['wymagane']);
        switch ($p['typ']) {
            case 'checkbox':
                $v = $v !== '' ? 'tak' : '';
                if ($req && $v === '') $e[$id] = 'zaznacz, aby kontynuować';
                break;
            case 'email':
                $v = strtolower($v);
                if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_EMAIL) || strlen($v) > 190)) $e[$id] = 'nieprawidłowy adres';
                elseif ($v !== '' && $email === '') $email = $v;
                break;
            case 'tel':
                if ($v !== '' && !preg_match('/^[0-9 +()\-]{6,24}$/', $v)) $e[$id] = 'nieprawidłowy numer';
                break;
            case 'number':
                if ($v !== '' && (!is_numeric(str_replace(',', '.', $v)) || (float) str_replace(',', '.', $v) < 0 || (float) str_replace(',', '.', $v) > 1e9)) $e[$id] = 'podaj liczbę';
                break;
            case 'nip':
                $d = preg_replace('/\D/', '', $v) ?? '';
                if ($v !== '') {
                    require_once __DIR__ . '/commerce.php';
                    if (!wsm_valid_nip($d)) $e[$id] = 'nieprawidłowy NIP (sprawdź cyfry)';
                    else $v = $d;
                }
                break;
            case 'select':
                if ($v !== '' && !in_array($v, (array) $p['opcje'], true)) $e[$id] = 'wybierz z listy';
                break;
            case 'date':
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $e[$id] = 'data w formacie RRRR-MM-DD';
                break;
            default:
                if (mb_strlen($v) > ($p['typ'] === 'textarea' ? 5000 : 300)) $e[$id] = 'za długie';
        }
        if ($req && $v === '' && !isset($e[$id])) $e[$id] = 'pole wymagane';
        $dane[$id] = $v;
    }
    if (empty($in['zgoda'])) $e['zgoda'] = 'zgoda wymagana, żebyśmy mogli odpowiedzieć';
    if ($e) return [null, $e];
    $json = json_encode($dane, JSON_UNESCAPED_UNICODE);
    // Une réponse de plus de 60 ko n'est pas un humain qui écrit : un robot
    // qui remplit tous les champs au maximum. Refusée avant la base.
    if ($json === false || strlen($json) > 60000) return [null, ['_limit' => 'zgłoszenie jest za długie — skróć wiadomość']];

    $nl = !empty($form['newsletter']) && !empty($in['zgoda_nl']) && $email !== '';
    $pdo->prepare("INSERT INTO wsm_form_entries (form_id, dane, email, status, zgoda_tekst, newsletter, ip, lang, created_at)
                   VALUES (?,?,?, 'nowe', ?,?,?,?,?)")
        ->execute([(int) $form['id'], $json, mb_substr($email, 0, 200),
                   mb_substr((string) ($teksty['zgoda'] ?? ''), 0, 600), $nl ? 1 : 0, $ip, substr($lang, 0, 5), date('Y-m-d H:i:s')]);
    $entryId = (int) $pdo->lastInsertId();

    // DANS LA MESSAGERIE, comme un courrier reçu : l'équipe lit Poczta, pas
    // un écran de plus. C'est aussi là que se compte le plafond par IP.
    $lignes = [];
    foreach ($form['pola_list'] as $p) {
        $v = (string) ($dane[$p['id']] ?? '');
        if ($v !== '') $lignes[] = $p['etykieta'] . ': ' . $v;
    }
    $corps = 'Formularz „' . $form['nazwa'] . '” (/f/' . $form['slug'] . ') · język: ' . $lang . "\n\n" . implode("\n", $lignes);
    wsm_mail_queue($pdo, ['email' => $email, 'direction' => 'wejscie',
                          'subject' => $form['nazwa'] . ' — ' . ($dane['firma'] ?? ($dane['imie'] ?? $email)),
                          'body' => $corps, 'actor' => 'formularz|' . $ip]);
    if ((string) $form['powiadom'] !== '') {
        wsm_mail_queue($pdo, ['email' => (string) $form['powiadom'], 'subject' => 'Nowe zgłoszenie: ' . $form['nazwa'],
                              'body' => $corps . "\n\nKonsola → Marketing → Formularze.", 'actor' => 'formularz']);
    }
    if ($nl) {
        try {
            require_once __DIR__ . '/marketing.php';
            wsm_mkt_subscribe($pdo, ['email' => $email, 'name' => (string) ($dane['imie'] ?? ''), 'lang' => $lang,
                                     'source' => substr('f:' . $form['slug'], 0, 20), 'newsletter' => true, 'sms' => false, 'ip' => $ip,
                                     'tekst_email' => (string) ($teksty['nl'] ?? '')]);
        } catch (Throwable $e2) { /* la réponse est enregistrée ; l'inscription se refait */ }
    }
    return [$entryId, []];
}

/** Les réponses d'un formulaire, les plus récentes d'abord. */
function wsm_form_entries(PDO $pdo, int $formId, string $status = '', int $n = 200): array {
    wsm_forms_ensure($pdo);
    $sql = "SELECT * FROM wsm_form_entries WHERE form_id = ?" . ($status !== '' ? " AND status = ?" : " AND status <> 'spam'")
         . " ORDER BY id DESC LIMIT " . max(1, min(2000, $n));
    $st = $pdo->prepare($sql);
    $st->execute($status !== '' ? [$formId, $status] : [$formId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['dane_list'] = json_decode((string) $r['dane'], true) ?: [];
    return $rows;
}

function wsm_form_entry_status(PDO $pdo, int $entryId, string $status, string $notatka = ''): bool {
    if (!isset(WSM_FORM_STATUSY[$status])) return false;
    $u = $pdo->prepare("UPDATE wsm_form_entries SET status = ?, notatka = ? WHERE id = ?");
    $u->execute([$status, mb_substr(trim($notatka), 0, 500), $entryId]);
    return $u->rowCount() > 0;
}

/** Effacement (RODO) : la réponse disparaît, rien n'en reste ici. */
function wsm_form_entry_delete(PDO $pdo, int $entryId): bool {
    $d = $pdo->prepare("DELETE FROM wsm_form_entries WHERE id = ?");
    $d->execute([$entryId]);
    return $d->rowCount() > 0;
}

/**
 * Les réponses en CSV (séparateur « ; », BOM UTF-8 : Excel en polonais
 * l'ouvre tel quel). Une cellule qui commence par = + - @ est neutralisée :
 * un « =HYPERLINK(…) » tapé dans le formulaire ne doit pas s'exécuter chez
 * celui qui ouvre le fichier.
 */
function wsm_form_csv(array $form, array $entries): string {
    $cell = static function (string $v): string {
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $head = array_merge(['Data', 'Status'], array_map(fn($p) => (string) $p['etykieta'], $form['pola_list']), ['Newsletter', 'Notatka']);
    $out = "\xEF\xBB\xBF" . implode(';', array_map($cell, $head)) . "\r\n";
    foreach ($entries as $e) {
        $row = [(string) $e['created_at'], WSM_FORM_STATUSY[(string) $e['status']] ?? (string) $e['status']];
        foreach ($form['pola_list'] as $p) $row[] = (string) ($e['dane_list'][$p['id']] ?? '');
        $row[] = (int) $e['newsletter'] === 1 ? 'tak' : '';
        $row[] = (string) $e['notatka'];
        $out .= implode(';', array_map($cell, $row)) . "\r\n";
    }
    return $out;
}
