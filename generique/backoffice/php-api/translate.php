<?php
// ============================================================================
//  translate.php — remplir les traductions manquantes avec Claude.
//
//  CE QUE CE FICHIER NE FAIT PAS. Il ne traduit jamais par-dessus un texte
//  existant. Une traduction relue par un humain vaut mieux qu'une traduction
//  automatique fraîche, toujours, et l'écraser serait détruire du travail
//  sans le dire. On ne remplit que le VIDE.
//
//  QUATRE PRÉCAUTIONS QUI COMPTENT PLUS QUE LA QUALITÉ DU MODÈLE :
//
//   1. SANS CLÉ, RIEN. Pas de demi-fonctionnement, pas de repli sur un
//      service tiers gratuit : la fonction se déclare indisponible et l'écran
//      n'affiche pas le bouton. « xxxx » ne compte pas comme une clé.
//   2. LE RÉSULTAT EST MARQUÉ `auto`. Une machine se trompe avec aplomb.
//      Tant qu'une personne n'a pas relu, l'écran doit pouvoir le dire —
//      sinon la seule information qui permet de relire est perdue.
//   3. LES PLACEHOLDERS SONT SACRÉS. « {qty} szt. » traduit en « {ilość} pcs »
//      casse le rendu : la variable ne sera pas remplacée et le client lira
//      « {ilość} ». On vérifie donc que chaque marqueur du texte source se
//      retrouve à l'identique, et on REFUSE la traduction sinon.
//   4. ON TRADUIT PAR LOTS, AVEC LE CONTEXTE. Une clé isolée (« Zamów »)
//      n'a pas de sens traduisible : bouton ? titre ? Le nom de la clé
//      (« checkout.submit ») et le lot voisin donnent le registre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

/** Le modèle : rapide et bon marché pour de la chaîne d'interface courte. */
const WSM_TR_MODEL = 'claude-sonnet-5';

/** Combien de clés par appel. Assez pour donner du contexte, pas trop pour
 *  qu'un refus fasse tout recommencer. */
const WSM_TR_BATCH = 40;

/**
 * La clé d'API : dans config.local.php, ou collée par le superadmin (table
 * wsm_settings, comme les autres secrets). Jamais dans ce dépôt, qui est public.
 */
function wsm_tr_key(): string {
    $k = trim((string) (wsm_config()['anthropic_api_key'] ?? ''));
    // « xxxx » est la marque de « pas encore renseigné » dans tout le projet.
    return ($k === '' || $k === 'xxxx') ? '' : $k;
}

/** La traduction automatique est-elle utilisable ? */
function wsm_tr_enabled(): bool { return wsm_tr_key() !== ''; }

/**
 * Les marqueurs d'un texte : {qty}, {total}, %s, %1$s.
 * Ils doivent traverser la traduction sans une égratignure.
 */
function wsm_tr_placeholders(string $s): array {
    $out = [];
    if (preg_match_all('/\{[a-z0-9_]+\}|%[0-9]*\$?[sd]/i', $s, $m)) $out = $m[0];
    sort($out);
    return $out;
}

/**
 * La traduction respecte-t-elle les marqueurs de la source ?
 *
 * Comparaison ensembliste : l'ordre des mots change d'une langue à l'autre,
 * donc l'ordre des marqueurs aussi. Ce qui ne doit pas changer, c'est
 * lesquels sont présents et combien de fois.
 */
function wsm_tr_placeholders_ok(string $src, string $out): bool {
    return wsm_tr_placeholders($src) === wsm_tr_placeholders($out);
}

/**
 * Appelle Claude sur un lot de chaînes.
 *
 * @param array  $lot  [clé => texte polonais]
 * @return array [traductions [clé => texte], erreur|null]
 */
function wsm_tr_call(array $lot, string $cible): array {
    $key = wsm_tr_key();
    if ($key === '') return [[], 'brak klucza API'];
    $nom = WSM_LANGS[$cible][2] ?? $cible;

    $sys = "Tu traduis l'interface d'une boutique en ligne de chocolat artisanal "
         . "polonaise (" . wsm_brand_name() . ") du polonais vers le $nom.\n\n"
         . "RÈGLES :\n"
         . "- Registre : commerçant soigné, chaleureux, jamais familier.\n"
         . "- Ce sont des chaînes d'INTERFACE : garde-les aussi courtes que la source. "
         . "Un bouton qui double de longueur casse la mise en page.\n"
         . "- La clé indique le contexte (checkout.submit = un bouton, meta.title = un titre de page).\n"
         . "- Les marqueurs comme {qty}, {total}, %s doivent être RECOPIÉS À L'IDENTIQUE, "
         . "sans les traduire ni les modifier.\n"
         . "- « " . wsm_brand_name() . " » ne se traduit jamais.\n"
         . "- Ne traduis pas les unités techniques (zł, kg, g).\n\n"
         . "Réponds UNIQUEMENT par un objet JSON {clé: traduction}, sans texte autour.";

    $payload = json_encode([
        'model' => WSM_TR_MODEL,
        'max_tokens' => 8000,
        'system' => $sys,
        'messages' => [['role' => 'user', 'content' => json_encode($lot, JSON_UNESCAPED_UNICODE)]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errc = curl_error($ch);
    curl_close($ch);

    if ($body === false) return [[], 'sieć: ' . $errc];
    if ($code !== 200) {
        // On ne renvoie JAMAIS le corps brut à l'écran : une réponse d'erreur
        // d'API peut contenir des fragments de la requête, donc du contenu.
        return [[], 'API odpowiedziało ' . $code];
    }

    $d = json_decode((string) $body, true);
    $txt = '';
    foreach ($d['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') $txt .= (string) $b['text'];
    }
    // Le modèle encadre parfois le JSON d'une clôture markdown malgré la
    // consigne : on récupère l'objet plutôt que d'échouer sur un détail.
    if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
    $out = json_decode($txt, true);
    if (!is_array($out)) return [[], 'odpowiedź nie jest JSON-em'];

    $prop = [];
    foreach ($out as $k => $v) {
        if (!is_string($v) || !isset($lot[$k])) continue;
        $prop[(string) $k] = trim($v);
    }
    return [$prop, null];
}

/**
 * Remplit les traductions manquantes d'une langue, sur une table.
 *
 * Ne touche que le vide (règle en tête de fichier). Chaque écriture est
 * marquée `auto` et journalisée, pour qu'on sache quoi relire.
 *
 * @param ?int $max  plafond de clés traitées (null = tout)
 * @return array ['written','skipped','placeholder_rejected','errors'=>string[]]
 */
function wsm_tr_fill(PDO $pdo, string $table, string $cible, string $actor, ?int $max = null): array {
    $r = ['written' => 0, 'skipped' => 0, 'placeholder_rejected' => 0, 'errors' => []];
    if (!wsm_tr_enabled())          { $r['errors'][] = 'tłumaczenie automatyczne nie jest skonfigurowane'; return $r; }
    if (!isset(WSM_LANG_TABLES[$table])) { $r['errors'][] = 'nieznana tabela'; return $r; }
    if ($cible === WSM_LANG_BASE)   { $r['errors'][] = 'polski jest źródłem — nie tłumaczy się go na siebie'; return $r; }
    if (!isset(WSM_LANGS[$cible]))  { $r['errors'][] = 'nieznany język'; return $r; }

    wsm_i18n_ensure_origin($pdo);
    $cov = wsm_lang_coverage($pdo, $table, $cible);
    $manquantes = $cov['missing'];
    if ($max !== null) $manquantes = array_slice($manquantes, 0, max(0, $max));
    if (!$manquantes) return $r;

    // Les textes polonais des clés à traduire.
    $src = [];
    $st = $pdo->prepare("SELECT k, v FROM $table WHERE lang = ?");
    $st->execute([WSM_LANG_BASE]);
    foreach ($st->fetchAll() ?: [] as $row) $src[(string) $row['k']] = (string) $row['v'];

    foreach (array_chunk($manquantes, WSM_TR_BATCH) as $lotClefs) {
        $lot = [];
        foreach ($lotClefs as $k) if (isset($src[$k]) && trim($src[$k]) !== '') $lot[$k] = $src[$k];
        if (!$lot) continue;

        [$prop, $err] = wsm_tr_call($lot, $cible);
        if ($err !== null) { $r['errors'][] = $err; break; }   // inutile d'insister lot après lot

        foreach ($prop as $k => $v) {
            if ($v === '') { $r['skipped']++; continue; }
            if (!wsm_tr_placeholders_ok($lot[$k], $v)) {
                // Un marqueur perdu affiche « {qty} » au client. On préfère
                // une case vide, qui retombe sur le polonais et se voit.
                $r['placeholder_rejected']++;
                continue;
            }
            // Rien n'a pu être écrit entre-temps ? On revérifie : deux
            // remplissages lancés en même temps ne doivent pas se marcher
            // dessus et retomber en `auto` sur du texte relu.
            if (trim(wsm_i18n_get($pdo, $table, $cible, $k)) !== '') { $r['skipped']++; continue; }

            wsm_i18n_put($pdo, $table, $cible, $k, $v, 'auto');
            wsm_i18n_log($pdo, $table, $cible, $k, '', $v, $actor, 'auto');
            $r['written']++;
        }
    }
    return $r;
}

/** Combien de textes attendent encore une relecture humaine. */
function wsm_tr_pending(PDO $pdo, string $lang): int {
    $n = 0;
    foreach (array_keys(WSM_LANG_TABLES) as $t) {
        if (!wsm_table_exists($pdo, $t)) continue;
        if (!in_array('origin', wsm_table_columns($pdo, $t), true)) continue;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE lang = ? AND origin = 'auto'");
            $st->execute([$lang]);
            $n += (int) $st->fetchColumn();
        } catch (Throwable $e) { /* rien */ }
    }
    return $n;
}

/** Marque un texte comme relu par un humain — il cesse d'être « à vérifier ». */
function wsm_tr_approve(PDO $pdo, string $table, string $lang, string $key): bool {
    if (!isset(WSM_LANG_TABLES[$table])) return false;
    if (!in_array('origin', wsm_table_columns($pdo, $table), true)) return false;
    try {
        $pdo->prepare("UPDATE $table SET origin = 'human' WHERE lang = ? AND k = ?")
            ->execute([$lang, $key]);
        return true;
    } catch (Throwable $e) { return false; }
}

// ============================================================================
//  TRADUCTION DU COURRIER (§11)
//
//  Trois règles, et la première décide de tout :
//
//   1. L'ORIGINAL NE SE REMPLACE JAMAIS. Ce que le client a écrit est la
//      pièce ; la traduction n'est qu'une aide à la lecture. Écraser le corps
//      du message par sa traduction ferait disparaître la seule version qui
//      fasse foi — et une machine se trompe.
//
//   2. RIEN NE PART SANS QU'UN HUMAIN AIT LU LE TEXTE TRADUIT. On propose la
//      traduction dans le champ de rédaction, modifiable ; on ne l'envoie pas
//      dans le dos de l'opérateur. Envoyer à un client une phrase que
//      personne n'a relue, c'est exactement ce qu'il ne faut pas faire.
//
//   3. UNE TRADUCTION SE PAIE UNE FOIS. Elle est rangée en base : rouvrir un
//      message ne rappelle pas l'API. Sans ça, relire trois fois un fil de
//      dix messages coûte trente traductions.
// ============================================================================

/**
 * Les lettres PROPRES à une langue. Trouver l'une d'elles est un signe fort.
 *
 * « ä ö ü » n'y sont PAS pour l'allemand : le hongrois, le turc, le finnois et
 * le suédois les emploient aussi, et « köszönöm » (hongrois, trois ö) faisait
 * gagner l'allemand sur un texte qui n'en contenait pas un mot. Seul « ß » est
 * vraiment allemand. La leçon vaut pour toutes les lignes : ce tableau ne
 * contient que ce qui n'appartient qu'à une langue.
 */
const WSM_TR_EXCLUSIF = [
    'uk' => '/[іїєґ]/iu',
    'pl' => '/[ąćęłńśźż]/iu',
    'cs' => '/[řůě]/iu',
    'sk' => '/[ľŕ]/iu',
    'hu' => '/[őű]/iu',
    'de' => '/[ß]/iu',
    'fr' => '/[çœ]/iu',
];

/**
 * Les lettres PARTAGÉES : un indice faible, qui ne doit jamais l'emporter
 * seul sur un mot reconnu.
 */
const WSM_TR_PARTAGE = [
    'de' => '/[äöü]/iu',
    'hu' => '/[áéíóúöü]/iu',
    'cs' => '/[áéíóúýč]/iu',
    'sk' => '/[áéíóúýôč]/iu',
    'fr' => '/[àâèéêëîïôùû]/iu',
];

/** Poids : un signe exclusif vaut plus qu'un mot, un mot plus qu'une lettre partagée. */
const WSM_TR_POIDS = ['exclusif' => 5, 'mot' => 3, 'partage' => 1];

/** Mots outils fréquents — départage quand les diacritiques manquent. */
const WSM_TR_MOTS = [
    'pl' => ['dzień dobry', 'proszę', 'dziękuję', 'zamówienie', 'czy', 'jest', 'nie', 'oraz'],
    'en' => ['hello', 'please', 'thanks', 'thank you', 'order', 'would', 'could', 'the '],
    'de' => ['guten tag', 'bitte', 'danke', 'bestellung', 'ich', 'und', 'nicht', 'sehr geehrte'],
    'fr' => ['bonjour', 'merci', 'commande', 'je ', 'nous', 'votre', 'cordialement', 'pouvez'],
    'uk' => ['доброго дня', 'дякую', 'замовлення', 'будь ласка', 'вітаю'],
    'cs' => ['dobrý den', 'děkuji', 'objednávka', 'prosím'],
    'sk' => ['dobrý deň', 'ďakujem', 'objednávka', 'prosím'],
    'hu' => ['jó napot', 'köszönöm', 'rendelés', 'kérem'],
];

/**
 * Dans quelle langue ce texte est-il écrit ?
 *
 * Heuristique d'abord, et c'est délibéré : un alphabet et huit mots outils
 * suffisent pour la quasi-totalité du courrier réel, gratuitement et
 * instantanément. Appeler un modèle pour reconnaître « Dzień dobry » serait
 * payer pour ce qu'on sait déjà.
 *
 * @return array [code, confiance 0..1] — 'pl' par défaut, la langue de la maison
 */
function wsm_tr_detect(string $texte): array {
    $t = mb_strtolower(trim($texte));
    if ($t === '') return ['pl', 0.0];

    $pts = [];
    $add = function (string $code, int $n) use (&$pts) { $pts[$code] = ($pts[$code] ?? 0) + $n; };

    // 1. Les lettres qui n'appartiennent qu'à une langue.
    foreach (WSM_TR_EXCLUSIF as $code => $motif) {
        $n = preg_match_all($motif, $t);
        if ($n) $add($code, $n * WSM_TR_POIDS['exclusif']);
    }
    // Du cyrillique sans lettre ukrainienne propre reste probablement de
    // l'ukrainien : c'est la seule langue cyrillique que la boutique sert.
    if (preg_match('/\p{Cyrillic}/u', $t)) $add('uk', WSM_TR_POIDS['exclusif']);

    // 2. Les mots outils : plus parlants qu'une lettre partagée.
    foreach (WSM_TR_MOTS as $code => $mots) {
        foreach ($mots as $mot) if (str_contains($t, $mot)) $add($code, WSM_TR_POIDS['mot']);
    }

    // 3. Les lettres communes à plusieurs langues, en dernier et au plus bas :
    //    elles départagent, elles ne décident pas.
    foreach (WSM_TR_PARTAGE as $code => $motif) {
        $n = preg_match_all($motif, $t);
        if ($n) $add($code, min($n, 6) * WSM_TR_POIDS['partage']);
    }

    if (!$pts) return ['pl', 0.0];
    arsort($pts);
    $codes = array_keys($pts);
    $premier = $codes[0];
    $second  = $pts[$codes[1] ?? ''] ?? 0;
    $total   = array_sum($pts);
    // La confiance est l'ÉCART avec le suivant, pas le score brut : deux
    // langues au coude à coude sont une ambiguïté, quel que soit le nombre
    // d'indices trouvés. L'écran s'en sert pour dire « niepewne ».
    $conf = $total > 0 ? round(($pts[$premier] - $second) / $total, 2) : 0.0;
    return [$premier, max(0.0, min(1.0, $conf))];
}

function wsm_tr_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_message_tr')) wsm_apply_schema($pdo);
}

/** La traduction déjà rangée, s'il y en a une. */
function wsm_tr_cached(PDO $pdo, int $messageId, string $lang): ?array {
    wsm_tr_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_message_tr WHERE message_id = ? AND lang = ?");
        $st->execute([$messageId, $lang]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Traduit un texte libre. Sert dans les deux sens : lire un message reçu en
 * polonais, ou rédiger une réponse dans la langue du client.
 *
 * @return array [texte|null, erreur|null]
 */
function wsm_tr_text(string $texte, string $source, string $cible): array {
    if (!wsm_tr_enabled())            return [null, 'tłumaczenie nie jest skonfigurowane'];
    if (trim($texte) === '')          return ['', null];
    if ($source === $cible)           return [$texte, null];
    if (!isset(WSM_LANGS[$cible]))    return [null, 'nieznany język docelowy'];

    $nomC = WSM_LANGS[$cible][2] ?? $cible;
    $nomS = WSM_LANGS[$source][2] ?? 'une langue inconnue';

    $sys = "Tu traduis la correspondance d'une boutique polonaise (" . wsm_brand_name() . ") "
         . "depuis le $nomS vers le $nomC.\n\n"
         . "RÈGLES :\n"
         . "- Traduis FIDÈLEMENT, sans résumer, sans ajouter de politesse absente, "
         . "sans corriger le fond. Une traduction qui arrondit fait prendre une décision sur "
         . "un message qui n'a pas été écrit.\n"
         . "- Garde la mise en forme : sauts de ligne, listes, numéros de commande, "
         . "références, montants et unités À L'IDENTIQUE.\n"
         . "- « " . wsm_brand_name() . " » ne se traduit pas.\n"
         . "- Registre commerçant soigné.\n"
         . "- Si le texte est déjà en $nomC, renvoie-le inchangé.\n\n"
         . "Réponds UNIQUEMENT par la traduction, sans préambule ni guillemets.";

    $payload = json_encode([
        'model' => WSM_TR_MODEL, 'max_tokens' => 4000, 'system' => $sys,
        'messages' => [['role' => 'user', 'content' => $texte]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['content-type: application/json',
                               'x-api-key: ' . wsm_tr_key(),
                               'anthropic-version: 2023-06-01'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errc = curl_error($ch);
    curl_close($ch);

    if ($body === false) return [null, 'sieć: ' . $errc];
    if ($code !== 200)   return [null, 'API odpowiedziało ' . $code];

    $d = json_decode((string) $body, true);
    $out = '';
    foreach ($d['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $out .= (string) $b['text'];
    $out = trim($out);
    return $out === '' ? [null, 'pusta odpowiedź'] : [$out, null];
}

/**
 * Traduit un message de la boîte, et RANGE le résultat.
 *
 * L'original reste intact en base : c'est la pièce. La traduction vit à côté,
 * dans sa propre table, et se retrouve à la réouverture sans repayer.
 *
 * @return array [traduction|null, erreur|null]
 */
function wsm_tr_message(PDO $pdo, int $messageId, string $cible, string $actor = ''): array {
    wsm_tr_ensure($pdo);
    if ($dejaLa = wsm_tr_cached($pdo, $messageId, $cible)) {
        return [$dejaLa, null];
    }
    $st = $pdo->prepare("SELECT id, subject, body FROM wsm_messages WHERE id = ?");
    $st->execute([$messageId]);
    $m = $st->fetch();
    if (!$m) return [null, 'nie znaleziono wiadomości'];

    [$src] = wsm_tr_detect((string) $m['subject'] . "\n" . (string) $m['body']);
    if ($src === $cible) {
        // Rien à traduire — mais on le RANGE quand même, avec la source
        // détectée : sinon l'écran redemande la traduction à chaque ouverture
        // d'un message déjà dans la bonne langue.
        $st2 = $pdo->prepare("INSERT INTO wsm_message_tr
                                (message_id, lang, src_lang, subject, body, actor, created_at)
                              VALUES (?,?,?,?,?,?,?)");
        try {
            $st2->execute([$messageId, $cible, $src, (string) $m['subject'], (string) $m['body'],
                           mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { /* course : déjà rangée */ }
        return [wsm_tr_cached($pdo, $messageId, $cible), null];
    }

    [$suj, $e1] = wsm_tr_text((string) $m['subject'], $src, $cible);
    if ($e1 !== null) return [null, $e1];
    [$cor, $e2] = wsm_tr_text((string) $m['body'], $src, $cible);
    if ($e2 !== null) return [null, $e2];

    $ins = $pdo->prepare("INSERT INTO wsm_message_tr
                            (message_id, lang, src_lang, subject, body, actor, created_at)
                          VALUES (?,?,?,?,?,?,?)");
    try {
        $ins->execute([$messageId, $cible, $src, (string) $suj, (string) $cor,
                       mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* course : une autre requête vient de la ranger */ }
    return [wsm_tr_cached($pdo, $messageId, $cible), null];
}

/**
 * La langue dans laquelle écrire à cette adresse.
 *
 * Par ordre de fiabilité : la langue déclarée sur une commande du client (il
 * l'a choisie lui-même sur la boutique), puis celle détectée dans son dernier
 * message, puis le polonais. Deviner à partir du seul texte quand on a une
 * réponse ferme en base serait perdre de l'information.
 */
function wsm_tr_lang_client(PDO $pdo, string $email): string {
    $email = strtolower(trim($email));
    if ($email === '') return WSM_LANG_BASE;
    try {
        $st = $pdo->prepare("SELECT lang FROM wsm_orders WHERE LOWER(email) = ?
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$email]);
        $l = (string) $st->fetchColumn();
        if ($l !== '' && isset(WSM_LANGS[$l])) return $l;
    } catch (Throwable $e) { /* pas de commande */ }

    try {
        $st = $pdo->prepare("SELECT subject, body FROM wsm_messages
                              WHERE LOWER(email) = ? AND direction = 'wejscie'
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$email]);
        if ($m = $st->fetch()) {
            [$code, $conf] = wsm_tr_detect((string) $m['subject'] . "\n" . (string) $m['body']);
            // Sous ce seuil, deux langues se disputent le texte : mieux vaut
            // le polonais assumé qu'un allemand deviné sur trois mots.
            if ($conf >= 0.25) return $code;
        }
    } catch (Throwable $e) { /* rien */ }
    return WSM_LANG_BASE;
}
