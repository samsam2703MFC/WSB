<?php
// ============================================================================
//  marketing_ai.php — rédiger et traduire avec Claude, sans jamais envoyer.
//
//  L'IA PROPOSE, UNE PERSONNE DISPOSE. Tout ce qui sort d'ici atterrit dans
//  le BROUILLON — newsletter ou SMS — et rien ne part sans relecture, sans
//  próbka, sans le bouton d'envoi. Une machine se trompe avec aplomb : une
//  remise inventée de 30 % envoyée à mille clients engage la boutique.
//
//  QUATRE GARDE-FOUS :
//   1. SANS CLÉ, RIEN (la même que translate.php) ; l'écran cache le bouton.
//   2. ON NE PROMET QUE CE QUI EXISTE : produits fournis, code fourni. Le
//      reste est refusé par la validation (wsm_nl_bloki_propres, liste des
//      produits autorisés).
//   3. UN SMS SE MESURE : la ligne de désinscription est comptée, et une
//      variante qui dépasse un SMS est signalée — le prix double.
//   4. LES MARQUEURS ({imie}, {kod}, {link}) TRAVERSENT INTACTS, sinon la
//      traduction est refusée (même règle que translate.php).
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/marketing.php';
require_once __DIR__ . '/translate.php';
require_once __DIR__ . '/newsletter.php';
require_once __DIR__ . '/smsapi.php';

/** Le modèle de rédaction. */
const WSM_AI_MODEL = WSM_TR_MODEL;            // le même modèle que les traductions : un seul endroit à changer

function wsm_ai_enabled(): bool { return wsm_tr_enabled(); }

function wsm_ai_http(string $system, string $user, int $max): array {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['model' => WSM_AI_MODEL, 'max_tokens' => $max, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]]], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . wsm_tr_key(), 'anthropic-version: 2023-06-01'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['', 'sieć: brak odpowiedzi'];
    if ($code !== 200) return ['', 'API odpowiedziało ' . $code];
    $d = json_decode((string) $body, true);
    $t = '';
    foreach ((array) ($d['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $t .= (string) $c['text'];
    return $t !== '' ? [$t, null] : ['', 'pusta odpowiedź'];
}

/** Le transport, remplaçable : les tests n'appellent jamais l'API. */
function wsm_ai_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_ai_http';
}

/** @return array{0:string, 1:?string} [texte, erreur] */
function wsm_ai_call(string $system, string $user, int $max = 3000): array {
    if (!wsm_ai_enabled()) return ['', 'brak klucza API (Integracje)'];
    try { return (wsm_ai_transport())($system, $user, $max); }
    catch (Throwable $e) { return ['', 'błąd: ' . $e->getMessage()]; }
}

/** Le premier objet JSON d'une réponse (les clôtures ``` sont tolérées). */
function wsm_ai_json(string $t): ?array {
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a === false || $b === false || $b < $a) return null;
    $d = json_decode(substr($t, $a, $b - $a + 1), true);
    return is_array($d) ? $d : null;
}

function wsm_ai_ton(): string {
    return "Piszesz dla " . wsm_brand_name() . " — polskiego sklepu internetowego z czekoladą i produktami cukierniczymi "
         . "(dla domu i dla firm). Ton: ciepły, rzeczowy, apetyczny, bez wykrzykników co zdanie, bez emoji. "
         . "Zwracasz się do klienta na „Ty”. NIGDY nie wymyślasz rabatów, cen, terminów ani właściwości zdrowotnych: "
         . "używasz WYŁĄCZNIE faktów podanych w poleceniu.";
}

/**
 * Un brouillon de newsletter.
 * @param list<string> $ids  produits que le texte peut montrer (et SEULEMENT eux)
 * @return array{0:?array, 1:?string} [['temat','preheader','bloki'], erreur]
 */
function wsm_ai_newsletter(PDO $pdo, string $brief, array $ids, string $kod = ''): array {
    $brief = trim($brief);
    if (mb_strlen($brief) < 5) return [null, 'Opisz w jednym-dwóch zdaniach, o czym ma być newsletter.'];
    $faits = [];
    $ok = [];
    foreach (array_slice($ids, 0, 6) as $pid) {
        $p = wsm_shop_product($pdo, (string) $pid, 'pl');
        if (!$p) continue;
        $ok[(string) $p['id']] = true;
        $faits[] = '- id ' . $p['id'] . ': ' . $p['name'] . ', ' . wsm_nl_zl((int) $p['price'])
                 . ($p['subtitle'] !== '' ? ' — ' . $p['subtitle'] : '');
    }
    $sys = wsm_ai_ton() . "\n\nZwracasz WYŁĄCZNIE obiekt JSON: {\"temat\":…, \"preheader\":…, \"bloki\":[…]}.\n"
         . "Bloki (pole \"typ\"): naglowek {tekst}, tekst {tekst; akapity rozdzielone pustą linią, **pogrubienie** dozwolone}, "
         . "produkty {ids: lista id z polecenia}, kod {kod, opis}, przycisk {etykieta}, odstep {}.\n"
         . "Temat do 60 znaków, preheader do 90. 3–6 bloków. Zacznij od nagłówka. Możesz użyć {imie} (imię klienta) najwyżej raz. "
         . "Blok kod tylko jeśli podano kod. Blok produkty tylko z podanymi id. Zakończ przyciskiem.";
    $user = "Polecenie: $brief\n\n" . ($faits ? "Produkty (wolno pokazać tylko te):\n" . implode("\n", $faits) . "\n\n" : "")
          . ($kod !== '' ? "Kod rabatowy: $kod (opisz go tylko ogólnie, bez wymyślania wysokości rabatu, chyba że podano ją w poleceniu).\n" : "Brak kodu rabatowego.\n");
    [$t, $err] = wsm_ai_call($sys, $user, 2500);
    if ($err !== null) return [null, $err];
    $d = wsm_ai_json($t);
    if (!$d) return [null, 'Odpowiedź nie dała się odczytać — spróbuj jeszcze raz.'];
    $bloki = wsm_nl_bloki_propres($d['bloki'] ?? []);
    foreach ($bloki as $i => $b) {
        if ($b['typ'] === 'produkty') {
            $bloki[$i]['ids'] = array_values(array_filter($b['ids'], fn($x) => isset($ok[$x])));
            if (!$bloki[$i]['ids']) unset($bloki[$i]);
        }
        if ($b['typ'] === 'kod' && $kod === '') unset($bloki[$i]);
        if ($b['typ'] === 'kod' && $kod !== '') $bloki[$i]['kod'] = $kod;
    }
    $bloki = array_values($bloki);
    if (!$bloki) return [null, 'Propozycja była pusta — spróbuj inaczej opisać temat.'];
    return [['temat' => mb_substr(trim((string) ($d['temat'] ?? '')), 0, 250),
             'preheader' => mb_substr(trim((string) ($d['preheader'] ?? '')), 0, 250), 'bloki' => $bloki], null];
}

/**
 * Trois propositions de SMS, mesurées : chacune avec son nombre de SMS
 * facturés une fois la ligne de désinscription ajoutée.
 * @return array{0:list<array{tekst:string, czesci:int, znaki:int}>, 1:?string}
 */
function wsm_ai_sms(PDO $pdo, string $brief, string $kod = '', bool $ascii = true): array {
    $brief = trim($brief);
    if (mb_strlen($brief) < 5) return [[], 'Opisz w jednym zdaniu, o czym ma być SMS.'];
    $pied = "\n" . str_replace('{stop}', wsm_mkt_sms_stop_url('abcd2345'), wsm_mkt_t($pdo, 'pl', 'nl.sms_footer', 'Rezygnacja: {stop}'));
    // La place qui reste dans UN SMS (160 en GSM-7) une fois la ligne de sortie comptée.
    $place = max(40, 160 - wsm_sms_parts($ascii ? wsm_sms_ascii($pied) : $pied)['znaki']);
    $sys = wsm_ai_ton() . "\n\nPiszesz SMS marketingowy. Zwracasz WYŁĄCZNIE obiekt JSON {\"warianty\":[\"…\",\"…\",\"…\"]} — trzy różne propozycje.\n"
         . "Każda do $place znaków (dopisek o rezygnacji dodajemy sami — NIE dodawaj go). Bez emoji. "
         . "Na początku nazwa sklepu (" . wsm_brand_name() . "). Możesz użyć {imie} i {link} (adres sklepu) najwyżej raz"
         . ($kod !== '' ? ", a kod rabatowy zapisz jako {kod}." : ".") . ($ascii ? " Pisz bez polskich znaków (a zamiast ą, l zamiast ł…), bo SMS jest wtedy tańszy." : "");
    $user = "Polecenie: $brief\n" . ($kod !== '' ? "Kod rabatowy: $kod\n" : "Brak kodu rabatowego — nie wspominaj o rabacie.\n");
    [$t, $err] = wsm_ai_call($sys, $user, 800);
    if ($err !== null) return [[], $err];
    $d = wsm_ai_json($t);
    $out = [];
    foreach ((array) ($d['warianty'] ?? []) as $v) {
        $v = trim(str_replace("\r", '', (string) $v));
        if ($v === '') continue;
        if ($ascii) $v = wsm_sms_ascii($v);
        $mesure = wsm_sms_texte($pdo, ['tresc' => $v, 'kod' => $kod, 'ascii' => $ascii ? 1 : 0, 'tlumaczenia' => []],
                                ['name' => 'Anna', 'lang' => 'pl', 'token' => 'abcd2345'], 's0');
        $p = wsm_sms_parts($mesure);
        $out[] = ['tekst' => mb_substr($v, 0, 600), 'czesci' => $p['czesci'], 'znaki' => $p['znaki']];
    }
    return $out ? [array_slice($out, 0, 3), null] : [[], 'Odpowiedź nie dała się odczytać — spróbuj jeszcze raz.'];
}

/**
 * Traduit une newsletter (objet, préheader, textes des blocs) vers uk ou en.
 * @return array{0:?array, 1:?string} [['temat','preheader','bloki'=>[i=>[champ=>texte]]], erreur]
 */
function wsm_ai_translate_nl(array $nl, string $cible): array {
    if (!in_array($cible, ['uk', 'en'], true)) return [null, 'nieznany język'];
    $src = ['temat' => (string) $nl['temat'], 'preheader' => (string) $nl['preheader']];
    foreach ((array) $nl['bloki'] as $i => $b) {
        foreach (WSM_NL_TEKSTY[(string) $b['typ']] ?? [] as $k) {
            if (trim((string) ($b[$k] ?? '')) !== '') $src["b$i.$k"] = (string) $b[$k];
        }
    }
    $nom = $cible === 'uk' ? 'ukraiński' : 'angielski';
    $sys = "Tłumaczysz newsletter sklepu " . wsm_brand_name() . " z polskiego na $nom. Ton ciepły, naturalny dla rodzimego czytelnika, "
         . "bez dosłowności. Znaczniki {imie}, {kod}, {link} oraz **pogrubienia** przepisz BEZ ZMIAN. Nazwy produktów i marki zostawiasz. "
         . "Zwracasz WYŁĄCZNIE obiekt JSON z tymi samymi kluczami.";
    [$t, $err] = wsm_ai_call($sys, json_encode($src, JSON_UNESCAPED_UNICODE), 4000);
    if ($err !== null) return [null, $err];
    $d = wsm_ai_json($t);
    if (!$d) return [null, 'Odpowiedź nie dała się odczytać — spróbuj jeszcze raz.'];
    $out = ['temat' => '', 'preheader' => '', 'bloki' => []];
    $refus = 0;
    foreach ($src as $k => $pl) {
        $v = trim((string) ($d[$k] ?? ''));
        if ($v === '' || !wsm_tr_placeholders_ok($pl, $v) || substr_count($pl, '**') !== substr_count($v, '**')) { $refus++; continue; }
        if (preg_match('/^b(\d+)\.(\w+)$/', $k, $m)) $out['bloki'][(int) $m[1]][$m[2]] = $v;
        else $out[$k] = $v;
    }
    return [$out, $refus > 0 ? $refus . ' tekst(y) odrzucone (zgubione znaczniki) — uzupełnij je ręcznie.' : null];
}

/** Traduit le texte d'un SMS (marqueurs intacts). @return array{0:string, 1:?string} */
function wsm_ai_translate_sms(string $texte, string $cible, bool $ascii = false): array {
    if (!in_array($cible, ['uk', 'en'], true)) return ['', 'nieznany język'];
    $nom = $cible === 'uk' ? 'ukraiński' : 'angielski';
    $sys = "Tłumaczysz SMS marketingowy sklepu " . wsm_brand_name() . " z polskiego na $nom. Zachowaj długość (SMS jest płatny za każde 160/70 znaków). "
         . "Znaczniki {imie}, {kod}, {link} przepisz BEZ ZMIAN. Bez emoji. Zwracasz WYŁĄCZNIE obiekt JSON {\"tekst\":\"…\"}.";
    [$t, $err] = wsm_ai_call($sys, $texte, 600);
    if ($err !== null) return ['', $err];
    $v = trim((string) (wsm_ai_json($t)['tekst'] ?? ''));
    if ($v === '' || !wsm_tr_placeholders_ok($texte, $v)) return ['', 'Tłumaczenie zgubiło znaczniki — popraw ręcznie.'];
    return [$cible === 'en' && $ascii ? wsm_sms_ascii($v) : $v, null];
}
