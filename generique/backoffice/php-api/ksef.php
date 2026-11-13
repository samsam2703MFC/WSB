<?php
// ============================================================================
//  ksef.php — Krajowy System e-Faktur : la facture n'existe qu'une fois
//  enregistrée par l'État.
//
//  CE QUE CE FICHIER PEUT ET NE PEUT PAS FAIRE, DIT TOUT DE SUITE.
//
//  Envoyer une facture au KSeF demande une session authentifiée : un jeton
//  d'autorisation délivré au NIP du vendeur, ET la clé publique du ministère
//  avec laquelle ce jeton est chiffré avant de partir. Nous n'avons ni l'un
//  ni l'autre. Ce module est donc écrit FERMÉ : sans ces deux éléments rien
//  ne part, et l'écran le dit. Même règle que tpay, InPost et Allegro — une
//  intégration à moitié branchée est pire qu'une absente, parce qu'on croit
//  avoir déclaré.
//
//  CE QUI EST QUAND MÊME UTILE AUJOURD'HUI, et qui se prouve hors ligne :
//
//   1. LE DOCUMENT XML FA(2) LUI-MÊME. C'est la partie longue et la seule
//      qui se trompe en silence : une ventilation de TVA rangée dans le
//      mauvais champ passe la validation et fausse la déclaration. Il se
//      construit ici, à partir de la facture FIGÉE, et se télécharge — on
//      peut le déposer à la main sur le portail du ministère dès aujourd'hui.
//   2. CE QUI EMPÊCHE D'ENVOYER, facture par facture, écrit en polonais.
//      Un NIP mal formé ou une vente intracommunautaire sans numéro de TVA
//      de l'acheteur se voient AVANT le dépôt, pas au contrôle.
//   3. CE QU'IL MANQUE POUR OUVRIR le canal, nommé.
//
//  CINQ RÈGLES :
//
//   1. SANS IDENTIFIANTS, TOUT EST FERMÉ. « xxxx » compte pour non configuré.
//
//   2. SEULES LES FACTURES FISCALES PARTENT. Un e-paragon et une proforma ne
//      sont pas des factures : les déposer inscrirait au registre national
//      des documents qui n'existent pas pour le fisc, et il faudrait ensuite
//      les corriger un par un.
//
//   3. LE NUMÉRO KSeF S'ÉCRIT UNE FOIS ET NE SE RÉÉCRIT JAMAIS. C'est
//      l'identité légale du document. Un second dépôt de la même facture
//      crée un DOUBLON au registre — que seule une correction efface.
//      L'idempotence est portée par la colonne `ksef_number` et par un
//      UPDATE gardé sur son vide.
//
//   4. LE XML SE CONSTRUIT DEPUIS LA FACTURE FIGÉE, jamais depuis la
//      commande. Pour la même raison que la facture est figée : le produit
//      peut changer de nom demain, le document déposé ne bouge plus.
//
//   5. LA DATE QUI COMPTE N'EST PAS CELLE QU'ON A IMPRIMÉE. Pour
//      l'administration, la facture est émise le jour où le KSeF l'ACCEPTE.
//      C'est pour ça qu'on garde `ksef_at` à part de `issued_at` au lieu
//      d'écraser l'un avec l'autre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice.php';

/** La version du schéma que nous produisons. */
const WSM_KSEF_WARIANT = '2';

/** L'espace de noms du gabarit FA(2) publié par le ministère. */
const WSM_KSEF_NS = 'http://crd.gov.pl/wzor/2023/06/29/12648/';

/** Nos genres de documents, et ce qu'ils deviennent dans FA(2). */
const WSM_KSEF_RODZAJE = ['faktura' => 'VAT', 'korekta' => 'KOR'];

/**
 * Les états que peut porter `ksef_status`. Vide = jamais touché.
 *
 * `odrzucona` est un état FINAL et bavard : le KSeF a refusé le document et
 * a dit pourquoi. On garde la raison plutôt que de remettre la facture dans
 * la file — la relancer telle quelle la ferait refuser à l'identique.
 */
const WSM_KSEF_STANY = [
    'oczekuje'  => 'Czeka na wysyłkę',
    'wyslana'   => 'Wysłana, czeka na UPO',
    'przyjeta'  => 'Przyjęta przez KSeF',
    'odrzucona' => 'Odrzucona przez KSeF',
    'blad'      => 'Błąd techniczny',
];

/** Le mode de paiement FA(2) que porte une vente encaissée par virement. */
const WSM_KSEF_FORMA_PLATNOSCI = '6';          // 6 = przelew

/** L'adresse de l'API, bac à sable ou production. */
function wsm_ksef_base(): string {
    $env = wsm_ksef_cfg()['env'];
    return match ($env) {
        'prod' => 'https://ksef.mf.gov.pl/api',
        'demo' => 'https://ksef-demo.mf.gov.pl/api',
        default => 'https://ksef-test.mf.gov.pl/api',
    };
}

/**
 * La configuration. « xxxx » est traité comme VIDE : c'est la valeur que
 * porte un champ de démonstration, et la prendre pour un jeton ouvrirait un
 * canal sur du vent.
 */
function wsm_ksef_cfg(): array {
    $c = wsm_config()['ksef'] ?? [];
    $net = function ($v): string {
        $v = trim((string) $v);
        return ($v === '' || strtolower($v) === 'xxxx') ? '' : $v;
    };
    $env = strtolower($net($c['env'] ?? '')) ?: 'test';
    return [
        'nip'        => wsm_ksef_nip($net($c['nip'] ?? '') ?: (string) (wsm_invoice_cfg()['seller_nip'] ?? '')),
        'token'      => $net($c['token'] ?? ''),
        'public_key' => $net($c['public_key'] ?? ''),
        'env'        => in_array($env, ['test', 'demo', 'prod'], true) ? $env : 'test',
    ];
}

/** Peut-on parler au KSeF ? */
function wsm_ksef_enabled(): bool {
    $c = wsm_ksef_cfg();
    return $c['nip'] !== '' && $c['token'] !== '' && $c['public_key'] !== ''
        && is_file($c['public_key']);
}

/**
 * Ce qu'il manque pour ouvrir le canal, nommé.
 *
 * « Nie skonfigurowano » ne dit pas quoi faire. Trois lignes qui nomment
 * l'endroit où chaque élément se récupère, si.
 */
function wsm_ksef_manquants(): array {
    $c = wsm_ksef_cfg();
    $out = [];
    if ($c['nip'] === '') {
        $out[] = 'NIP sprzedawcy (10 cyfr) — z ekranu Ustawienia, sekcja faktur';
    }
    if ($c['token'] === '') {
        $out[] = 'token autoryzacyjny KSeF — generowany w aplikacji KSeF na NIP sprzedawcy, '
               . 'trzymany wyłącznie po stronie serwera';
    }
    if ($c['public_key'] === '') {
        $out[] = 'ścieżka do klucza publicznego Ministerstwa Finansów — bez niego tokenu '
               . 'nie da się zaszyfrować, więc sesji nie da się otworzyć';
    } elseif (!is_file($c['public_key'])) {
        $out[] = 'plik klucza publicznego nie istnieje pod podaną ścieżką: ' . $c['public_key'];
    }
    return $out;
}

// ---------------------------------------------------------------------------
//  Petites conversions — chacune est un endroit où l'on se trompe en silence
// ---------------------------------------------------------------------------

/** Un NIP nu : sans espaces, sans tirets, sans préfixe pays. 10 chiffres ou ''. */
function wsm_ksef_nip(string $raw): string {
    $s = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
    if (str_starts_with($s, 'PL')) $s = substr($s, 2);
    return preg_match('/^[0-9]{10}$/', $s) ? $s : '';
}

/** Un numéro de TVA intracommunautaire : [code pays, numéro] ou ['', '']. */
function wsm_ksef_vat_eu(string $raw): array {
    $s = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
    if (!preg_match('/^([A-Z]{2})([A-Z0-9]{2,12})$/', $s, $m)) return ['', ''];
    return [$m[1], $m[2]];
}

/**
 * Les grosze en zlotys, tels que le schéma les attend : point décimal, deux
 * décimales, pas de séparateur de milliers. `1234` devient `12.34`.
 */
function wsm_ksef_kwota(int $grosze): string {
    return number_format($grosze / 100, 2, '.', '');
}

/** Le taux tel que FA(2) l'écrit dans P_12 : 0.23 devient « 23 ». */
function wsm_ksef_stawka(float $rate): string {
    return (string) ((int) round($rate * 100));
}

/**
 * L'adresse figée est une chaîne ; FA(2) veut un code pays et deux lignes.
 *
 * Nos adresses se terminent par le code pays sur deux lettres (c'est ce
 * qu'écrit le tunnel de commande). Quand il n'y est pas, on suppose la
 * Pologne plutôt que de refuser : une facture polonaise sans pays écrit
 * reste une facture polonaise.
 *
 * @return array{0:string,1:string,2:string} [kod kraju, AdresL1, AdresL2]
 */
function wsm_ksef_adres(string $addr): array {
    $parts = array_values(array_filter(array_map('trim', explode(',', $addr)), fn($p) => $p !== ''));
    $kod = 'PL';
    if ($parts && preg_match('/^[A-Za-z]{2}$/', (string) end($parts))) {
        $kod = strtoupper((string) array_pop($parts));
    }
    $l1 = (string) ($parts[0] ?? '');
    $l2 = implode(', ', array_slice($parts, 1));
    return [$kod, mb_substr($l1, 0, 512), mb_substr($l2, 0, 512)];
}

/** L'échappement XML. ENT_XML1 et pas ENT_HTML5 : `&nbsp;` n'existe pas ici. */
function wsm_ksef_x(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ---------------------------------------------------------------------------
//  Ce qui empêche d'envoyer
// ---------------------------------------------------------------------------

/**
 * Les raisons pour lesquelles CETTE facture ne peut pas partir, en polonais
 * et au complet — pas la première trouvée.
 *
 * Rendre la liste entière plutôt que s'arrêter au premier défaut évite la
 * réparation en escalier : on corrige le NIP, on relance, on découvre qu'il
 * manquait aussi le numéro de TVA de l'acheteur.
 */
function wsm_ksef_blockers(PDO $pdo, array $inv): array {
    $out = [];
    $kind = (string) ($inv['kind'] ?? '');

    if (!isset(WSM_KSEF_RODZAJE[$kind])) {
        // Règle 2. Ce n'est pas un manque à combler : c'est un document qui
        // n'a rien à faire au registre national.
        return [$kind === 'paragon'
            ? 'e-paragon nie jest fakturą — KSeF go nie przyjmuje i nie powinien'
            : 'proforma nie jest dokumentem księgowym — do KSeF nie trafia'];
    }

    if (trim((string) ($inv['ksef_number'] ?? '')) !== '') {
        $out[] = 'ta faktura ma już numer KSeF — drugie wysłanie utworzyłoby duplikat w rejestrze';
    }
    if (wsm_ksef_nip((string) ($inv['seller_nip'] ?? '')) === '') {
        $out[] = 'NIP sprzedawcy nie jest dziesięciocyfrowy';
    }
    if (trim((string) ($inv['seller_name'] ?? '')) === '') {
        $out[] = 'brak nazwy sprzedawcy';
    }
    if (trim((string) ($inv['buyer_name'] ?? '')) === '') {
        $out[] = 'brak nazwy nabywcy';
    }
    if (!($inv['items'] ?? [])) {
        $out[] = 'faktura bez pozycji';
    }
    if (strtoupper((string) ($inv['currency'] ?? 'PLN')) !== 'PLN') {
        // FA(2) accepte d'autres devises, mais exige alors le cours et sa
        // date. Nous n'avons ni l'un ni l'autre : mieux vaut le dire que
        // déposer un document au cours implicite de 1.
        $out[] = 'waluta inna niż PLN wymaga kursu i daty kursu — tego nie mamy';
    }
    if ($kind === 'faktura' && (int) ($inv['total_gross'] ?? 0) <= 0) {
        $out[] = 'faktura na kwotę zerową';
    }

    // La vente intracommunautaire : c'est ICI que l'exonération se gagne ou
    // se perd. Sans le numéro de TVA de l'acheteur au document, le 0 % n'est
    // pas justifié et le contrôle le reprend au taux polonais.
    if (!empty($inv['reverse_charge'])) {
        [$kod, $num] = wsm_ksef_vat_eu((string) ($inv['buyer_vat_eu'] ?? ''));
        if ($num === '') {
            $out[] = 'dostawa wewnątrzwspólnotowa bez numeru VAT-UE nabywcy — '
                   . 'stawka 0 % byłaby nieudokumentowana';
        }
        if ((int) ($inv['total_vat'] ?? 0) !== 0) {
            $out[] = 'dostawa wewnątrzwspólnotowa z niezerowym VAT — dokument sam sobie przeczy';
        }
    }

    // Une correction doit désigner l'originale. Que l'originale soit ou non
    // au registre n'est pas un défaut : le schéma prévoit les deux cas. En
    // revanche, une correction orpheline ne désigne rien.
    if ($kind === 'korekta') {
        $src = wsm_ksef_korygowana($pdo, $inv);
        if ($src === null) {
            $out[] = 'korekta nie wskazuje faktury pierwotnej';
        }
    }

    return $out;
}

/** La facture d'origine d'une correction, ou null. */
function wsm_ksef_korygowana(PDO $pdo, array $inv): ?array {
    $id = (int) ($inv['corrects_id'] ?? 0);
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM wsm_invoices WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
//  La ventilation de TVA — le seul endroit où une erreur est invisible
// ---------------------------------------------------------------------------

/**
 * FA(2) ne veut pas d'un tableau de taux : il veut des CHAMPS NOMMÉS, un par
 * taux, et il faut ranger chaque montant dans le bon. Se tromper de champ ne
 * déclenche aucune erreur de validation — le document passe, et la
 * déclaration de TVA est fausse.
 *
 *   P_13_1 / P_14_1   base et TVA à 23 %
 *   P_13_2 / P_14_2   base et TVA à 8 %
 *   P_13_3 / P_14_3   base et TVA à 5 %
 *   P_13_6_1          base à 0 % en vente intérieure
 *   P_13_6_2          base à 0 % en livraison intracommunautaire (WDT)
 *   P_13_7            base exonérée
 *
 * Nous ne vendons que des biens, et notre exonération vient d'une livraison
 * intracommunautaire à un assujetti vérifié : le 0 % va donc en P_13_6_2 et
 * pas en P_13_6_1. C'est la distinction qui décide de la ligne du
 * récapitulatif VAT-UE.
 *
 * @return array<string,string> champ => montant en zlotys
 */
function wsm_ksef_pola_vat(array $inv): array {
    $wdt = !empty($inv['reverse_charge']);
    $acc = [];
    $add = function (string $pole, int $grosze) use (&$acc): void {
        $acc[$pole] = ($acc[$pole] ?? 0) + $grosze;
    };

    foreach ((array) ($inv['items'] ?? []) as $l) {
        $pct = (int) round(((float) $l['vat_rate']) * 100);
        $net = (int) $l['line_net'];
        $vat = (int) $l['line_vat'];
        if ($wdt || $pct === 0)  { $add($wdt ? 'P_13_6_2' : 'P_13_6_1', $net); continue; }
        if ($pct === 23)         { $add('P_13_1', $net); $add('P_14_1', $vat); continue; }
        if ($pct === 8)          { $add('P_13_2', $net); $add('P_14_2', $vat); continue; }
        if ($pct === 5)          { $add('P_13_3', $net); $add('P_14_3', $vat); continue; }
        // Un taux que le schéma ne connaît pas ne s'invente pas : on le range
        // en exonéré, où il reste VISIBLE plutôt que dilué dans le 23 % — et
        // `wsm_ksef_stawki_obce()` le signale à l'écran.
        $add('P_13_7', $net);
    }

    // L'ordre compte : le schéma est une séquence, pas un sac.
    $ordre = ['P_13_1', 'P_14_1', 'P_13_2', 'P_14_2', 'P_13_3', 'P_14_3',
              'P_13_6_1', 'P_13_6_2', 'P_13_7'];
    $out = [];
    foreach ($ordre as $p) if (isset($acc[$p])) $out[$p] = wsm_ksef_kwota($acc[$p]);
    return $out;
}

/** Les taux présents sur la facture que FA(2) ne sait pas nommer. */
function wsm_ksef_stawki_obce(array $inv): array {
    if (!empty($inv['reverse_charge'])) return [];
    $out = [];
    foreach ((array) ($inv['items'] ?? []) as $l) {
        $pct = (int) round(((float) $l['vat_rate']) * 100);
        if (!in_array($pct, [0, 5, 8, 23], true)) $out[$pct] = $pct;
    }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------------------
//  Le document
// ---------------------------------------------------------------------------

/**
 * Le XML FA(2) d'une facture. Construit depuis la facture FIGÉE (règle 4) :
 * cette fonction ne lit ni le catalogue, ni la commande, ni la configuration
 * du vendeur d'aujourd'hui.
 *
 * `$now` est un paramètre et non un `date()` caché : c'est ce qui permet de
 * comparer deux exécutions dans les tests au lieu de croire sur parole.
 */
function wsm_ksef_xml(PDO $pdo, array $inv, string $now = ''): string {
    $now = $now !== '' ? $now : date('c');
    $x   = fn($s) => wsm_ksef_x((string) $s);
    $kind = (string) ($inv['kind'] ?? 'faktura');
    $rodzaj = WSM_KSEF_RODZAJE[$kind] ?? 'VAT';

    [$sKod, $sL1, $sL2] = wsm_ksef_adres((string) ($inv['seller_address'] ?? ''));
    [$bKod, $bL1, $bL2] = wsm_ksef_adres((string) ($inv['buyer_address'] ?? ''));
    [$vKod, $vNum] = wsm_ksef_vat_eu((string) ($inv['buyer_vat_eu'] ?? ''));
    $buyerNip = wsm_ksef_nip((string) ($inv['buyer_nip'] ?? ''));

    $o = [];
    $o[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $o[] = '<Faktura xmlns="' . WSM_KSEF_NS . '">';

    $o[] = '  <Naglowek>';
    $o[] = '    <KodFormularza kodSystemowy="FA (2)" wersjaSchemy="1-0E">FA</KodFormularza>';
    $o[] = '    <WariantFormularza>' . WSM_KSEF_WARIANT . '</WariantFormularza>';
    $o[] = '    <DataWytworzeniaFa>' . $x($now) . '</DataWytworzeniaFa>';
    $o[] = '    <SystemInfo>' . htmlspecialchars(wsm_brand_name(), ENT_XML1) . ' back office</SystemInfo>';
    $o[] = '  </Naglowek>';

    // Podmiot1 — le vendeur, tel qu'il était au jour de l'émission.
    $o[] = '  <Podmiot1>';
    $o[] = '    <DaneIdentyfikacyjne>';
    $o[] = '      <NIP>' . $x(wsm_ksef_nip((string) $inv['seller_nip'])) . '</NIP>';
    $o[] = '      <Nazwa>' . $x(mb_substr((string) $inv['seller_name'], 0, 256)) . '</Nazwa>';
    $o[] = '    </DaneIdentyfikacyjne>';
    $o[] = '    <Adres>';
    $o[] = '      <KodKraju>' . $x($sKod) . '</KodKraju>';
    $o[] = '      <AdresL1>' . $x($sL1) . '</AdresL1>';
    if ($sL2 !== '') $o[] = '      <AdresL2>' . $x($sL2) . '</AdresL2>';
    $o[] = '    </Adres>';
    $o[] = '  </Podmiot1>';

    // Podmiot2 — l'acheteur. Trois identités possibles, et il faut choisir :
    // un numéro de TVA intracommunautaire pour la vente WDT, un NIP pour une
    // entreprise polonaise, et l'absence assumée d'identifiant pour un
    // particulier. `BrakID` n'est pas un défaut de données : c'est ce que le
    // schéma attend d'une facture à une personne physique.
    $o[] = '  <Podmiot2>';
    $o[] = '    <DaneIdentyfikacyjne>';
    if ($vNum !== '') {
        $o[] = '      <KodUE>' . $x($vKod) . '</KodUE>';
        $o[] = '      <NrVatUE>' . $x($vNum) . '</NrVatUE>';
    } elseif ($buyerNip !== '') {
        $o[] = '      <NIP>' . $x($buyerNip) . '</NIP>';
    } else {
        $o[] = '      <BrakID>1</BrakID>';
    }
    $o[] = '      <Nazwa>' . $x(mb_substr((string) $inv['buyer_name'], 0, 256)) . '</Nazwa>';
    $o[] = '    </DaneIdentyfikacyjne>';
    $o[] = '    <Adres>';
    $o[] = '      <KodKraju>' . $x($bKod) . '</KodKraju>';
    $o[] = '      <AdresL1>' . $x($bL1) . '</AdresL1>';
    if ($bL2 !== '') $o[] = '      <AdresL2>' . $x($bL2) . '</AdresL2>';
    $o[] = '    </Adres>';
    $o[] = '  </Podmiot2>';

    $o[] = '  <Fa>';
    $o[] = '    <KodWaluty>' . $x(strtoupper((string) ($inv['currency'] ?? 'PLN'))) . '</KodWaluty>';
    $o[] = '    <P_1>' . $x((string) $inv['issued_at']) . '</P_1>';
    $o[] = '    <P_2>' . $x((string) $inv['number']) . '</P_2>';
    $o[] = '    <P_6>' . $x((string) $inv['sold_at']) . '</P_6>';
    foreach (wsm_ksef_pola_vat($inv) as $pole => $kwota) {
        $o[] = '    <' . $pole . '>' . $kwota . '</' . $pole . '>';
    }
    $o[] = '    <P_15>' . wsm_ksef_kwota((int) $inv['total_gross']) . '</P_15>';

    // Adnotacje : le schéma exige que chaque mention soit prise ou refusée
    // explicitement. 2 = non, 1 = oui. Un champ absent est un document
    // rejeté, pas un « non » implicite.
    $o[] = '    <Adnotacje>';
    $o[] = '      <P_16>2</P_16>';
    $o[] = '      <P_17>2</P_17>';
    $o[] = '      <P_18>2</P_18>';
    $o[] = '      <P_18A>2</P_18A>';
    $o[] = '      <Zwolnienie><P_19N>1</P_19N></Zwolnienie>';
    $o[] = '      <NoweSrodkiTransportu><P_22N>1</P_22N></NoweSrodkiTransportu>';
    $o[] = '      <P_23>2</P_23>';
    $o[] = '      <PMarzy><P_PMarzyN>1</P_PMarzyN></PMarzy>';
    $o[] = '    </Adnotacje>';

    $o[] = '    <RodzajFaktury>' . $rodzaj . '</RodzajFaktury>';

    if ($rodzaj === 'KOR') {
        $src = wsm_ksef_korygowana($pdo, $inv);
        $o[] = '    <PrzyczynaKorekty>' . $x(mb_substr((string) ($inv['note'] ?? ''), 0, 256)) . '</PrzyczynaKorekty>';
        // 2 = la correction produit ses effets à sa propre date d'émission.
        // C'est le cas d'une remise accordée après coup, qui est le nôtre.
        $o[] = '    <TypKorekty>2</TypKorekty>';
        $o[] = '    <DaneFaKorygowanej>';
        $o[] = '      <DataWystFaKorygowanej>' . $x((string) ($src['issued_at'] ?? $inv['issued_at'])) . '</DataWystFaKorygowanej>';
        $o[] = '      <NrFaKorygowanej>' . $x((string) ($src['number'] ?? '')) . '</NrFaKorygowanej>';
        // L'originale est-elle au registre ? Les deux réponses sont légales,
        // mais elles n'utilisent pas le même champ, et se tromper fait
        // rejeter la correction.
        $nr = trim((string) ($src['ksef_number'] ?? ''));
        if ($nr !== '') {
            $o[] = '      <NrKSeF>1</NrKSeF>';
            $o[] = '      <NrKSeFFaKorygowanej>' . $x($nr) . '</NrKSeFFaKorygowanej>';
        } else {
            $o[] = '      <NrKSeFN>1</NrKSeFN>';
        }
        $o[] = '    </DaneFaKorygowanej>';
    }

    $i = 0;
    foreach ((array) ($inv['items'] ?? []) as $l) {
        $i++;
        $qty = max(1, (int) $l['qty']);
        $o[] = '    <FaWiersz>';
        $o[] = '      <NrWierszaFa>' . $i . '</NrWierszaFa>';
        $o[] = '      <P_7>' . $x(mb_substr((string) $l['name'], 0, 256)) . '</P_7>';
        $o[] = '      <P_8A>szt.</P_8A>';
        $o[] = '      <P_8B>' . $qty . '</P_8B>';
        $o[] = '      <P_9A>' . wsm_ksef_kwota((int) $l['unit_net']) . '</P_9A>';
        $o[] = '      <P_11>' . wsm_ksef_kwota((int) $l['line_net']) . '</P_11>';
        $o[] = '      <P_12>' . (!empty($inv['reverse_charge']) ? '0' : $x(wsm_ksef_stawka((float) $l['vat_rate']))) . '</P_12>';
        $o[] = '    </FaWiersz>';
    }

    $o[] = '    <Platnosc>';
    if (!empty($inv['paid'])) {
        $o[] = '      <Zaplacono>1</Zaplacono>';
        $o[] = '      <DataZaplaty>' . $x((string) $inv['issued_at']) . '</DataZaplaty>';
    } else {
        $o[] = '      <TerminPlatnosci><Termin>' . $x((string) $inv['due_at']) . '</Termin></TerminPlatnosci>';
    }
    $o[] = '      <FormaPlatnosci>' . WSM_KSEF_FORMA_PLATNOSCI . '</FormaPlatnosci>';
    $iban = preg_replace('/\s+/', '', (string) ($inv['iban'] ?? '')) ?? '';
    if ($iban !== '') {
        $o[] = '      <RachunekBankowy>';
        $o[] = '        <NrRB>' . $x($iban) . '</NrRB>';
        if (trim((string) ($inv['bank'] ?? '')) !== '') {
            $o[] = '        <NazwaBanku>' . $x(mb_substr((string) $inv['bank'], 0, 256)) . '</NazwaBanku>';
        }
        $o[] = '      </RachunekBankowy>';
    }
    $o[] = '    </Platnosc>';
    $o[] = '  </Fa>';
    $o[] = '</Faktura>';

    return implode("\n", $o) . "\n";
}

/** Le nom du fichier à déposer. Pas d'espaces, pas de barres obliques. */
function wsm_ksef_nazwa_pliku(array $inv): string {
    $n = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $inv['number']) ?? 'faktura';
    return 'ksef-' . trim($n, '-') . '.xml';
}

// ---------------------------------------------------------------------------
//  La file
// ---------------------------------------------------------------------------

/**
 * Les factures qui devraient être au registre et n'y sont pas, chacune avec
 * ce qui l'en empêche.
 *
 * L'e-paragon et la proforma ne sont pas dans la requête : les faire
 * apparaître pour les marquer bloqués donnerait une file de cent lignes dont
 * quatre-vingt-dix-neuf n'ont rien à y faire.
 */
function wsm_ksef_queue(PDO $pdo, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $rows = $pdo->query("SELECT * FROM wsm_invoices
                          WHERE kind IN ('faktura','korekta') AND ksef_number = ''
                            AND ksef_status <> 'odrzucona'
                          ORDER BY id DESC LIMIT $limit")->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $inv = wsm_invoice_hydrate($pdo, $r);
        $out[] = [
            'inv'      => $inv,
            'blockers' => wsm_ksef_blockers($pdo, $inv),
            'obce'     => wsm_ksef_stawki_obce($inv),
        ];
    }
    return $out;
}

/**
 * Les compteurs, calculés SUR LA FILE affichée et pas sur une seconde requête.
 *
 * Deux requêtes avec deux limites différentes donnent deux vérités, et c'est
 * le bouton qui ment : « wyślij wszystkie gotowe (300) » sous une liste de
 * deux cents.
 */
function wsm_ksef_kpis(PDO $pdo, ?array $file = null): array {
    $file ??= wsm_ksef_queue($pdo);
    $gotowe = 0; $zablokowane = 0; $kwota = 0;
    foreach ($file as $x) {
        if ($x['blockers']) { $zablokowane++; continue; }
        $gotowe++;
        $kwota += (int) $x['inv']['total_gross'];
    }
    $n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    return [
        'gotowe'      => $gotowe,
        'zablokowane' => $zablokowane,
        'kwota'       => $kwota,
        'przyjete'    => $n("SELECT COUNT(*) FROM wsm_invoices WHERE ksef_number <> ''"),
        'odrzucone'   => $n("SELECT COUNT(*) FROM wsm_invoices WHERE ksef_status = 'odrzucona'"),
        // Ce que la file NE MONTRE PAS. Sans ce nombre, un écran qui affiche
        // deux cents lignes sur trois cent soixante se lit « voilà tout ce
        // qui reste » — et le jour de la bascule il en manque cent soixante
        // que personne n'a jamais vues. Une troncature muette se lit comme
        // une couverture complète.
        'wszystkie'   => wsm_ksef_poza_rejestrem($pdo),
    ];
}

/** Combien de factures sont hors registre EN TOUT, sans limite d'affichage. */
function wsm_ksef_poza_rejestrem(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices
                               WHERE kind IN ('faktura','korekta') AND ksef_number = ''
                                 AND ksef_status <> 'odrzucona'")->fetchColumn();
}

// ---------------------------------------------------------------------------
//  L'écriture du résultat
// ---------------------------------------------------------------------------

/**
 * Inscrit le résultat d'un dépôt. RÈGLE 3 : le numéro KSeF ne s'écrit qu'une
 * fois. L'UPDATE est gardé sur `ksef_number = ''`, donc deux exécutions
 * concurrentes ne peuvent pas en écrire deux — la seconde ne touche aucune
 * ligne et le sait.
 *
 * @return bool vrai si c'est bien CET appel qui a écrit
 */
function wsm_ksef_mark(PDO $pdo, int $id, string $status, string $number = '', string $note = ''): bool {
    $status = isset(WSM_KSEF_STANY[$status]) ? $status : 'blad';
    $number = trim($number);

    if ($number !== '') {
        $st = $pdo->prepare("UPDATE wsm_invoices
                                SET ksef_number = ?, ksef_status = ?, ksef_at = ?
                              WHERE id = ? AND ksef_number = ''");
        $st->execute([$number, $status, date('Y-m-d H:i:s'), $id]);
        return $st->rowCount() > 0;
    }

    // Un état sans numéro (attente, refus, panne) se réécrit librement : il
    // ne porte aucune identité légale. Mais jamais sur une facture déjà
    // inscrite — un refus ne peut pas venir après une acceptation.
    $st = $pdo->prepare("UPDATE wsm_invoices SET ksef_status = ?, ksef_at = ?
                          WHERE id = ? AND ksef_number = ''");
    $st->execute([$status, date('Y-m-d H:i:s'), $id]);
    if ($note !== '' && $st->rowCount() > 0) {
        $pdo->prepare("UPDATE wsm_invoices SET note = ? WHERE id = ? AND note = ''")
            ->execute([mb_substr($note, 0, 250), $id]);
    }
    return $st->rowCount() > 0;
}

// ---------------------------------------------------------------------------
//  L'envoi — fermé, et qui explique pourquoi
// ---------------------------------------------------------------------------

/**
 * Ce que l'envoi ferait, et pourquoi il ne le fait pas.
 *
 * On ne renvoie pas `false` : un « non » sans motif se retrouve trois mois
 * plus tard sous forme de « le KSeF ne marche pas ». Le motif est écrit.
 *
 * @return array{wyslane:int, pominiete:int, message:string}
 */
function wsm_ksef_run(PDO $pdo, string $actor = '', ?array $file = null): array {
    $file ??= wsm_ksef_queue($pdo);
    $gotowe = array_values(array_filter($file, fn($x) => !$x['blockers']));

    if (!wsm_ksef_enabled()) {
        $m = wsm_ksef_manquants();
        return ['wyslane' => 0, 'pominiete' => count($gotowe),
                'message' => 'Kanał KSeF jest zamknięty, więc nic nie poszło. Brakuje: '
                           . implode(' · ', $m) . '. Dokumenty XML można pobrać i złożyć ręcznie.'];
    }
    if (!$gotowe) {
        return ['wyslane' => 0, 'pominiete' => 0,
                'message' => 'Nie ma czego wysłać — każda faktura albo jest już w rejestrze, albo ma blokadę.'];
    }

    // Le canal ouvert, le dépôt se fait facture par facture, et le résultat
    // s'inscrit immédiatement : une panne au milieu d'un lot de cent ne doit
    // pas laisser les cinquante premières sans trace.
    $ok = 0; $ko = 0;
    foreach ($gotowe as $x) {
        $inv = $x['inv'];
        [$numer, $err] = wsm_ksef_wyslij($pdo, $inv, $actor);
        if ($numer !== null) { wsm_ksef_mark($pdo, (int) $inv['id'], 'przyjeta', $numer); $ok++; }
        else                 { wsm_ksef_mark($pdo, (int) $inv['id'], 'blad', '', (string) $err); $ko++; }
    }
    return ['wyslane' => $ok, 'pominiete' => $ko,
            'message' => $ok . ' faktur w rejestrze KSeF' . ($ko > 0 ? ", $ko nie przeszło" : '.')];
}

/**
 * Le dépôt d'UNE facture.
 *
 * La session KSeF se noue en trois temps : on demande un défi (challenge),
 * on chiffre le jeton d'autorisation avec la clé publique du ministère et
 * l'horodatage du défi, puis on ouvre la session avec ce paquet. Sans la
 * clé, le deuxième temps est impossible — c'est pour ça que le fichier de
 * clé fait partie des identifiants requis et pas d'un réglage optionnel.
 *
 * @return array{0:?string,1:?string} [numer KSeF, błąd]
 */
function wsm_ksef_wyslij(PDO $pdo, array $inv, string $actor = ''): array {
    if (!wsm_ksef_enabled()) return [null, 'kanał zamknięty'];
    $c = wsm_ksef_cfg();

    $challenge = wsm_ksef_http('POST', '/online/Session/AuthorisationChallenge', [
        'contextIdentifier' => ['type' => 'onip', 'identifier' => $c['nip']],
    ]);
    if (($challenge['ok'] ?? false) !== true) {
        return [null, 'nie udało się otworzyć sesji: ' . (string) ($challenge['error'] ?? 'brak odpowiedzi')];
    }

    $key = @file_get_contents($c['public_key']);
    $enc = null;
    if (is_string($key) && $key !== '') {
        $ts = (string) ($challenge['body']['timestamp'] ?? '');
        $ms = $ts !== '' ? (string) (strtotime($ts) * 1000) : '';
        $plain = $c['token'] . '|' . $ms;
        $pub = @openssl_pkey_get_public($key);
        if ($pub && @openssl_public_encrypt($plain, $out, $pub, OPENSSL_PKCS1_PADDING)) {
            $enc = base64_encode($out);
        }
    }
    if ($enc === null) {
        return [null, 'nie udało się zaszyfrować tokenu kluczem Ministerstwa Finansów'];
    }

    $init = wsm_ksef_http('POST', '/online/Session/InitToken', [
        'challenge' => (string) ($challenge['body']['challenge'] ?? ''),
        'token'     => $enc,
    ]);
    $sess = (string) ($init['body']['sessionToken']['token'] ?? '');
    if ($sess === '') return [null, 'sesja KSeF nie została otwarta'];

    $xml = wsm_ksef_xml($pdo, $inv);
    $put = wsm_ksef_http('PUT', '/online/Invoice/Send', [
        'invoiceHash' => ['hashSHA' => ['algorithm' => 'SHA-256', 'encoding' => 'Base64',
                                        'value' => base64_encode(hash('sha256', $xml, true))],
                          'fileSize' => strlen($xml)],
        'invoicePayload' => ['type' => 'plain', 'invoiceBody' => base64_encode($xml)],
    ], $sess);
    $ref = (string) ($put['body']['elementReferenceNumber'] ?? '');
    if ($ref === '') return [null, 'KSeF nie przyjął dokumentu'];

    if ($actor !== '' && function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'ksef', (string) $inv['number'], '');
    }
    return [$ref, null];
}

/**
 * Un appel HTTP au KSeF. Rendu séparé pour que rien d'autre dans ce fichier
 * ne parle au réseau : c'est ce qui permet de tester tout le reste hors ligne.
 */
function wsm_ksef_http(string $method, string $path, array $body = [], string $session = ''): array {
    $h = ['Content-Type: application/json', 'Accept: application/json'];
    if ($session !== '') $h[] = 'SessionToken: ' . $session;

    $ch = curl_init(wsm_ksef_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw)) return ['ok' => false, 'error' => $err ?: 'brak połączenia', 'body' => []];
    $j = json_decode($raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code,
            'error' => $code >= 400 ? (string) ($j['exception']['exceptionDetailList'][0]['exceptionDescription'] ?? "HTTP $code") : '',
            'body' => is_array($j) ? $j : []];
}
