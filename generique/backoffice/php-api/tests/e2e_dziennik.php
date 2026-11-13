<?php
// ============================================================================
//  e2e_dziennik.php — le journal des erreurs, et son panneau du Pulpit.
//
//  En production, une requête refusée par MySQL et rattrapée par un « catch »
//  qui rend un tableau vide ne laissait AUCUNE trace : c'est ainsi qu'une
//  date comparée à '' a fait afficher 0 dans le B2B et le CRM sans que
//  personne le sache. Ce qui est démontré :
//
//   1. TOUTE REQUÊTE REFUSÉE EST ÉCRITE, avec la requête — y compris quand
//      le code l'avale ensuite. Le comportement ne change pas : l'exception
//      remonte telle quelle.
//   2. PAS D'AVALANCHE : la même erreur dans une requête ne s'écrit qu'une fois.
//   3. LE PANNEAU LIT LE JOURNAL, le plus récent d'abord ; « przejrzane »
//      archive ce qui a été lu, sans rien effacer.
//   4. Il n'est montré qu'à qui gère les réglages, et l'archivage exige le
//      jeton du formulaire.
//
//  Le journal de développement est mis de côté puis remis : le test ne laisse
//  rien derrière lui.
//
//  Usage :  php tests/e2e_dziennik.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
$pdo = wsm_bootstrap();
echo "webshop_mrszoko — end-to-end dziennik błędów\n\n";

$f = wsm_journal_plik();
$avant  = is_file($f) ? file_get_contents($f) : null;
$avant1 = is_file($f . '.1') ? file_get_contents($f . '.1') : null;
@unlink($f); @unlink($f . '.1');
putenv('WSM_JOURNAL=1');                 // la ligne de commande n'écrit pas, sauf demande
$sfx = bin2hex(random_bytes(3));

// ---- 1. Chaque refus est écrit -----------------------------------------------------------
echo "-- każde odrzucone zapytanie jest zapisane --\n";
ok('la connexion passe par WsmPDO, les requêtes par WsmStatement', $pdo instanceof WsmPDO && $pdo->prepare('SELECT 1') instanceof WsmStatement);
$remonte = false;
try { $pdo->query("SELECT kolumna_$sfx FROM wsm_orders"); } catch (PDOException $e) { $remonte = true; }
ok('l\'exception remonte toujours (le comportement ne change pas)', $remonte);
try { $st = $pdo->prepare("SELECT 1 FROM wsm_orders WHERE id = ?"); $st->execute([1]);
      $pdo->prepare("UPDATE wsm_orders SET kolumna2_$sfx = 1 WHERE id = ?")->execute([1]); } catch (Throwable $e) { /* avalée, comme dans le code */ }
try { $pdo->exec("DELETE FROM tabela_$sfx"); } catch (Throwable $e) {}
$j = (string) @file_get_contents($f);
ok('un refus de query() est écrit avec la requête', str_contains($j, "SQL: SELECT kolumna_$sfx FROM wsm_orders"), $j);
ok('… celui de prepare()/execute() aussi, même avalé par un catch', str_contains($j, "kolumna2_$sfx"));
ok('… et celui de exec()', str_contains($j, "tabela_$sfx"));
ok('chaque ligne : date | où | quoi', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \| [^|]+ \| .+$/m', $j));
$n = substr_count($j, "\n");
try { $pdo->query("SELECT kolumna_$sfx FROM wsm_orders"); } catch (Throwable $e) {}
ok('la même erreur dans la même requête ne s\'écrit qu\'une fois', substr_count((string) @file_get_contents($f), "\n") === $n);

// ---- 2. Ce que le panneau lit ------------------------------------------------------------
echo "\n-- co czyta panel --\n";
$l = wsm_journal_lire(2);
ok('les comptes : tout, et les 7 derniers jours', $l['razem'] === 3 && $l['tydzien'] === 3, [$l['razem'], $l['tydzien']]);
ok('le plus récent d\'abord, limité à ce qu\'on demande', count($l['wpisy']) === 2 && str_contains($l['wpisy'][0]['co'], "tabela_$sfx"), $l['wpisy']);
ok('chaque entrée découpée en kiedy / gdzie / co', $l['wpisy'][0]['kiedy'] !== '' && $l['wpisy'][0]['gdzie'] !== '' && str_starts_with($l['wpisy'][0]['co'], 'SQLSTATE'));
ok('« od » : la date de la première entrée', $l['od'] === substr($j, 0, 19));
ok('« przejrzane » archive : la liste repart de zéro, les lignes vont en .1', wsm_journal_archiwizuj() && wsm_journal_lire()['razem'] === 0
   && str_contains((string) @file_get_contents($f . '.1'), "kolumna_$sfx"));
ok('… et sur un journal absent, ce n\'est pas une erreur', wsm_journal_archiwizuj());
putenv('WSM_JOURNAL');
try { $pdo->query("SELECT kolumna3_$sfx FROM wsm_orders"); } catch (Throwable $e) {}
ok('sans WSM_JOURNAL, la ligne de commande (les tests) n\'écrit rien', !is_file($f));

// ---- 3. Le panneau du Pulpit -------------------------------------------------------------
echo "\n-- panel na Pulpicie --\n";
$pulpit = (string) @file_get_contents(dirname(__DIR__, 2) . '/pulpit.php');
ok('montré à qui gère les réglages (mêmes droits qu\'Integracje)', str_contains($pulpit, "\$bledyWidac = console_ouvre(\$me, 'ustawienia.php');"));
ok('l\'archivage exige le jeton du formulaire et laisse une trace d\'audit', str_contains($pulpit, 'console_csrf_ok() && wsm_journal_archiwizuj()') && str_contains($pulpit, "'Przegląd', 'dziennik błędów"));
ok('le texte du journal est échappé à l\'affichage', str_contains($pulpit, "h(mb_strimwidth(\$w['co']") && str_contains($pulpit, "title=\"<?= h(\$w['co']) ?>\""));
$api = (string) @file_get_contents(dirname(__DIR__) . '/.htaccess');
ok('le journal ne se sert pas par le web (data/ en 404, .log refusé)', str_contains($api, 'RedirectMatch 404 /backoffice/api/(data|tests|schema)/') && str_contains($api, '|log|log\.1)$'));

// ---- Remise en place -------------------------------------------------------------------------
@unlink($f); @unlink($f . '.1');
if ($avant !== null) file_put_contents($f, $avant);
if ($avant1 !== null) file_put_contents($f . '.1', $avant1);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
