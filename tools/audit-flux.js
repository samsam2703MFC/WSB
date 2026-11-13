// ============================================================================
//  audit-flux.js — on CLIQUE. Ouvrir une page ne prouve rien.
//
//  POURQUOI CE FICHIER EXISTE, À CÔTÉ DE audit-ui.js. L'autre harnais CHARGE
//  les écrans : il attrape un 500, un débordement, un champ muet. Il ne
//  touche à rien. Or tout ce qu'on livre ici est fait de boutons qui
//  ÉCRIVENT — émettre une facture, mettre des relances en file, marquer un
//  colis parti. Un bouton peut s'afficher parfaitement et ne rien faire, ou
//  faire la mauvaise chose : la page se recharge, un bandeau vert s'affiche,
//  et rien n'a bougé en base. Aucun test hors ligne ne voit ça, parce que la
//  panne est dans le trajet formulaire → serveur → base, pas dans la règle.
//
//  TROIS RÈGLES, apprises en se trompant :
//
//   1. ON MESURE L'EFFET EN BASE, PAS LE BANDEAU. « Wysłano 12 przypomnień »
//      est une phrase ; douze lignes de plus dans wsm_messages est un fait.
//      Chaque flux déclare ce qu'il doit changer, et on compte avant/après.
//
//   2. CE QU'ON N'EXERCE PAS EST DIT. Les actions destructrices (supprimer,
//      désactiver) ne sont pas jouées : rejouer un audit ne doit pas vider la
//      boutique. Elles sont ÉNUMÉRÉES et listées comme non couvertes — un
//      rapport qui tait ce qu'il n'a pas essayé se lit comme un rapport
//      complet.
//
//   3. UN BOUTON QUI NE CHANGE RIEN EST UN ÉCHEC, pas un détail. C'est même
//      le défaut le plus cher : personne ne le remarque avant d'en avoir eu
//      besoin.
//
//  Usage :
//    cp -r generique/backoffice/php-api/data <site>/backoffice/api/data
//    php -S localhost:8093 -t <site déployé>   (php-api renommé en api)
//    node tools/audit-flux.js <cookie-de-session> <chemin-sqlite>
// ============================================================================
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const { execFileSync } = require('child_process');

const SESS = process.argv[2];
const DB = process.argv[3];
const BO = 'http://localhost:8093/backoffice';
const SHOP = 'http://localhost:8093/shop';

const ok = [], ko = [], nonCouvert = [], inventaire = [];

/** Compte une ligne en base. Le seul juge : le bandeau ne compte pas. */
function compte(sql) {
  const out = execFileSync('php', ['-r',
    `$p=new PDO("sqlite:" . $argv[1]); echo (int)$p->query($argv[2])->fetchColumn();`,
    '--', DB, sql], { encoding: 'utf8' });
  return parseInt(out, 10);
}

/** Les mots qui désignent une action qu'on ne rejoue pas sur un audit. */
const DESTRUCTIF = /usuń|usun|skasuj|wyłącz|wylacz|dezaktyw|delete|remove|anuluj/i;

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'pl-PL' });
  await ctx.addCookies([{ name: 'WSMSESS', value: SESS, domain: 'localhost', path: '/' }]);
  const p = await ctx.newPage();
  const erreurs = [];
  p.on('pageerror', e => erreurs.push(e.message.slice(0, 90)));

  // Les gestes irréversibles sont protégés par un confirm(). Playwright ferme
  // les boîtes de dialogue TOUT SEUL, donc sans ceci le formulaire n'est jamais
  // soumis — la page ne bouge pas, le rapport lit « aucun effet en base » et
  // accuse le produit d'une faute de harnais. On accepte explicitement : c'est
  // l'humain qui cliquerait « oui » qu'on simule.
  p.on('dialog', d => d.accept());

  // ---- 1. INVENTAIRE : que peut-on cliquer, écran par écran ? --------------
  // On ne peut pas prétendre couvrir « tous les boutons » sans les avoir
  // d'abord COMPTÉS. Le reste du fichier se juge contre ce chiffre.
  const ECRANS = execFileSync('php', ['-r',
    `require_once $argv[1]; foreach (console_sections(["role"=>"Centrala"]) as $i)
       foreach (array_keys($i) as $f) echo $f, "\\n";`,
    '--', `${__dirname}/../generique/backoffice/console.php`], { encoding: 'utf8' })
    .trim().split('\n');

  for (const e of ECRANS) {
    erreurs.length = 0;
    const r = await p.goto(`${BO}/${e}`, { waitUntil: 'load', timeout: 30000 });
    if (!r || r.status() >= 400) { ko.push(`${e} : HTTP ${r ? r.status() : 0}`); continue; }
    const actions = await p.evaluate(() => {
      const out = [];
      document.querySelectorAll('form').forEach(f => {
        f.querySelectorAll('button, input[type=submit]').forEach(b => {
          const t = (b.textContent || b.value || '').trim().replace(/\s+/g, ' ').slice(0, 40);
          if (t) out.push({ kind: 'form', label: t, method: (f.method || 'get').toUpperCase() });
        });
      });
      return out;
    });
    // Un écran listant quarante produits porte quarante fois « Usuń trwale ».
    // Les énumérer un par un noie le rapport sous la même ligne répétée : on
    // compte les OCCURRENCES et on n'écrit le libellé qu'une fois.
    const parLabel = new Map();
    for (const a of actions) parLabel.set(a.label, (parLabel.get(a.label) || 0) + 1);
    inventaire.push({ ecran: e, actions, distincts: parLabel });
    for (const [label, n] of parLabel) {
      if (DESTRUCTIF.test(label)) {
        nonCouvert.push(`${e} › « ${label} »${n > 1 ? ` ×${n}` : ''} (destructif — jamais rejoué)`);
      }
    }
    if (erreurs.length) ko.push(`${e} : erreur JS — ${erreurs[0]}`);
  }

  // ---- 2. LES FLUX QUI ÉCRIVENT -------------------------------------------
  // Chacun déclare ce qu'il doit changer EN BASE. Un flux dont le compteur ne
  // bouge pas échoue, même si la page affiche un bandeau vert.
  //
  // Les boutons se désignent par leur ATTRIBUT `name`, jamais par leur texte.
  // Le premier jet cherchait /nadaj/i et tombait sur « Nadaj zaznaczone » au
  // lieu de « Nadaj wszystkie gotowe » : deux boutons voisins, deux gestes
  // différents, et un rapport qui accusait le produit d'une faute de harnais.
  //
  // `attendu` : 'augmente' quand le geste doit écrire ; 'refuse' quand il doit
  // POLIMENT ne rien faire (canal fermé, rien de coché). Un refus propre est
  // un comportement à prouver, pas une absence de test — c'est même ce qui
  // sépare une intégration fermée d'une intégration cassée.
  const FLUX = [
    { nom: 'Faktury › wystaw fakturę', url: `${BO}/faktury.php`, bouton: 'wystaw',
      mesure: 'SELECT COUNT(*) FROM wsm_invoices', attendu: 'augmente' },

    { nom: 'Faktury › proforma', url: `${BO}/faktury.php`, bouton: 'proforma_zam',
      mesure: "SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'proforma'", attendu: 'augmente' },

    { nom: 'Faktury › monit o płatność', url: `${BO}/faktury.php`, bouton: 'monit',
      mesure: 'SELECT COUNT(*) FROM wsm_messages', attendu: 'augmente' },

    { nom: 'Przypomnienia › wyślij do kolejki', url: `${BO}/przypomnienia.php`, bouton: 'wyslij',
      mesure: 'SELECT COUNT(*) FROM wsm_messages', attendu: 'augmente' },

    // FORMULAIRE VIDE ENVOYÉ EXPRÈS. Ces deux gestes créent des documents à
    // partir de champs qu'on ne va pas inventer ici — un stock reçu et un bon
    // de réduction sont de vraies écritures. Ce qu'on exige alors est l'autre
    // moitié du contrat, et elle vaut d'être prouvée : un formulaire vide doit
    // être REFUSÉ EN LE DISANT, jamais enregistré à blanc.
    { nom: 'Magazyn › przyjęcie vide → refus', url: `${BO}/magazyn.php`, bouton: 'przyjmij',
      mesure: 'SELECT COUNT(*) FROM wsm_stock_moves', attendu: 'refuse' },

    { nom: 'Rabaty › kod vide → refus', url: `${BO}/rabaty.php`, bouton: 'bon_save',
      mesure: 'SELECT COUNT(*) FROM wsm_vouchers', attendu: 'refuse' },

    { nom: 'Kampanie › przygotuj', url: `${BO}/kampanie.php`, bouton: 'przygotuj',
      mesure: 'SELECT COUNT(*) FROM wsm_messages', attendu: 'quelconque' },

    { nom: 'Subskrypcje › przelicz', url: `${BO}/subskrypcje.php`, bouton: 'przelicz',
      mesure: 'SELECT COUNT(*) FROM wsm_orders', attendu: 'quelconque' },

    { nom: 'Allegro › przelicz plan', url: `${BO}/allegro.php`, bouton: 'przelicz',
      mesure: 'SELECT COUNT(*) FROM wsm_products', attendu: 'quelconque' },

    // Canal transporteur fermé : les boutons ne doivent PAS être là, et un POST
    // forgé ne doit rien tenter. « Nadaj wszystkie gotowe (146) » sur un canal
    // fermé, c'est cent quarante-six appels voués à l'échec et un compteur qui
    // promet ce qu'il ne peut pas tenir.
    { nom: 'Wysyłka › nadaj zaznaczone (canal fermé)', url: `${BO}/wysylka.php`,
      cocher: 'input[name="zam[]"]:not([disabled])', bouton: 'nadaj',
      mesure: 'SELECT COUNT(*) FROM wsm_shipments', attendu: 'refuse' },

    { nom: 'Wysyłka › nadaj wszystkie gotowe (canal fermé)', url: `${BO}/wysylka.php`,
      bouton: 'nadaj_gotowe',
      mesure: 'SELECT COUNT(*) FROM wsm_shipments', attendu: 'refuse' },

    // Idem KSeF : un numéro inscrit sans session est un doublon au registre de
    // l'État, que seule une correction efface.
    { nom: 'KSeF › złóż w KSeF (canal fermé)', url: `${BO}/ksef.php`, bouton: 'wyslij',
      mesure: "SELECT COUNT(*) FROM wsm_invoices WHERE ksef_number <> ''", attendu: 'refuse' },
  ];

  for (const f of FLUX) {
    erreurs.length = 0;
    await p.goto(f.url, { waitUntil: 'load', timeout: 30000 });

    if (f.cocher) {
      const c = p.locator(f.cocher).first();
      if (await c.count() === 0) {
        nonCouvert.push(`${f.nom} — aucune case cochable dans cet état de la base`);
        continue;
      }
      await c.check();
    }

    // Deux façons de porter l'action, et il faut couvrir les deux : soit le
    // bouton porte le `name` lui-même, soit le formulaire porte un champ caché
    // et son bouton n'a pas de nom. Ne chercher que la première laissait onze
    // gestes déclarés « absents » alors qu'ils étaient à l'écran — un rapport
    // qui se croit exhaustif parce qu'il cherche mal.
    const b = p.locator(
      `button[name="${f.bouton}"], input[type=submit][name="${f.bouton}"], `
      + `form:has(input[name="${f.bouton}"]) button[type=submit], `
      + `form:has(input[name="${f.bouton}"]) input[type=submit]`).first();
    if (await b.count() === 0) {
      nonCouvert.push(`${f.nom} — bouton « ${f.bouton} » absent (rien à faire dans cet état)`);
      continue;
    }

    // LA VALIDATION DU NAVIGATEUR N'EST PAS UN SILENCE. Un formulaire qui
    // porte des champs `required` n'est jamais soumis tant qu'ils sont vides :
    // le navigateur affiche sa bulle et bloque. Sans ce test, le harnais
    // cliquait, ne voyait ni écriture ni bandeau, et criait au « refus muet »
    // alors que le refus était parfaitement visible — à l'écran, pas dans le
    // HTML. Un audit qui invente un défaut coûte la confiance qu'on met dans
    // les vrais.
    const valide = await b.evaluate(el => {
      const fo = el.closest('form');
      return !fo || typeof fo.checkValidity !== 'function' || fo.checkValidity();
    });
    if (!valide) {
      nonCouvert.push(`${f.nom} — bloqué par la validation du navigateur `
        + `(champs obligatoires vides) : le geste demande de vraies données`);
      continue;
    }

    const avant = compte(f.mesure);
    await b.click();
    await p.waitForLoadState('load');
    const apres = compte(f.mesure);
    // `p.flash` et RIEN d'autre. Chercher aussi `.ok` attrapait une pastille
    // d'état au milieu d'un tableau : le rapport citait « OK » comme s'il
    // s'agissait de la réponse du serveur, ce qui est exactement le genre de
    // faux témoignage qu'un audit doit éviter de produire lui-même.
    const flash = (await p.locator('p.flash').first().textContent().catch(() => '') || '')
      .trim().replace(/\s+/g, ' ').slice(0, 90);
    const corps = await p.locator('body').innerText().catch(() => '');

    if (/Fatal error|Uncaught|Warning:/i.test(corps)) {
      ko.push(`${f.nom} : la page a craché — ${corps.slice(0, 90)}`);
    } else if (f.attendu === 'augmente' && apres <= avant) {
      // LE défaut cher : le bouton répond, la base ne bouge pas.
      ko.push(`${f.nom} : AUCUN EFFET EN BASE (${avant} → ${apres}) — bandeau : « ${flash} »`);
    } else if (f.attendu === 'refuse' && apres !== avant) {
      ko.push(`${f.nom} : A ÉCRIT alors qu'il devait refuser (${avant} → ${apres})`);
    } else if (f.attendu === 'refuse') {
      // Un refus MUET est un demi-refus : la personne reclique. On exige donc
      // que le canal fermé se dise, pas seulement qu'il n'écrive rien.
      (flash ? ok : ko).push(flash
        ? `${f.nom} : refus propre, rien écrit  « ${flash} »`
        : `${f.nom} : n'a rien écrit — mais N'A RIEN DIT non plus (refus muet)`);
    } else {
      ok.push(`${f.nom} : ${avant} → ${apres}  « ${flash} »`);
    }
    if (erreurs.length) ko.push(`${f.nom} : erreur JS — ${erreurs[0]}`);
  }

  // ---- 3. LE TÉLÉCHARGEMENT XML KSeF --------------------------------------
  // Un lien de téléchargement se vérifie par CE QU'IL REND, pas par son code
  // HTTP : un 200 qui renvoie du HTML d'erreur est un 200.
  await p.goto(`${BO}/ksef.php`, { waitUntil: 'load' });
  const lien = p.locator('a[href*="ksef.php?xml="]').first();
  if (await lien.count() === 0) {
    nonCouvert.push('KSeF › Pobierz XML — aucune facture hors registre à télécharger');
  } else {
    const href = await lien.getAttribute('href');
    const res = await p.request.get(`${BO}/${href}`);
    const corps = await res.text();
    const bon = res.status() === 200
      && corps.startsWith('<?xml')
      && corps.includes('<Faktura')
      && (res.headers()['content-disposition'] || '').includes('.xml');
    (bon ? ok : ko).push(`KSeF › Pobierz XML : ${res.status()}, ${corps.length} o, `
      + (bon ? 'FA(2) bien formé' : `PAS un XML FA(2) — début : ${corps.slice(0, 60)}`));
  }

  // ---- 4. LE PARCOURS CLIENT, DE BOUT EN BOUT ------------------------------
  // C'est le seul flux qui rapporte de l'argent. Il se conduit en entier :
  // catalogue → panier → caisse. Un panier qui n'additionne pas ne se voit
  // dans aucun test de règle.
  const p2 = await ctx.newPage();
  try {
    await p2.goto(`${SHOP}/`, { waitUntil: 'load', timeout: 30000 });
    // Le formulaire d'ajout se désigne par SON formulaire (`form[data-add]`),
    // pas par « le premier bouton de la page » : celui-là est le sélecteur de
    // langue de l'en-tête. Le premier jet cliquait dessus, trouvait le panier
    // vide, et concluait que « do koszyka » ne faisait rien — alors que le
    // parcours qui rapporte l'argent marchait parfaitement.
    const ajout = p2.locator('form[data-add] button[type=submit]').first();
    if (await ajout.count() === 0) {
      ko.push('Sklep : aucun bouton « do koszyka » sur le catalogue');
    } else {
      // « Dodaj do koszyka » NE NAVIGUE PAS : shop.js intercepte le submit et
      // POSTe en fetch, désactive le bouton, puis affiche une confirmation.
      // Filer vers /koszyk juste après le clic coupait la requête en vol — le
      // panier arrivait vide et le harnais accusait le seul parcours qui
      // rapporte de l'argent. On attend le signal DE L'APPLICATION elle-même
      // (la note de confirmation), pas un délai deviné.
      await ajout.click();
      await p2.locator('.added-note').first().waitFor({ state: 'visible', timeout: 10000 })
        .catch(() => {});
      await p2.goto(`${SHOP}/koszyk`, { waitUntil: 'load' });
      // On affirme du POSITIF : une ligne d'article avec son bouton « Usuń »
      // et un prix. Chercher l'ABSENCE du mot « pusty » se trompe dès qu'il
      // apparaît ailleurs dans la page — et fait accuser à tort le seul
      // parcours qui rapporte de l'argent.
      const lignes = await p2.locator('form[action*="koszyk"] button, a:has-text("Usuń"), button:has-text("Usuń")').count();
      const corps = await p2.locator('body').innerText();
      const aPrix = /\d+[ ,]\d{2}\s*zł/.test(corps);
      const bon = lignes > 0 && aPrix;
      (bon ? ok : ko).push(bon
        ? `Sklep : catalogue → panier, article ajouté (${lignes} contrôles de ligne, prix affiché)`
        : `Sklep : après « do koszyka », rien dans le panier (lignes=${lignes}, prix=${aPrix})`);
      const r = await p2.goto(`${SHOP}/kasa`, { waitUntil: 'load' });
      (r.status() === 200 ? ok : ko).push(`Sklep : caisse → HTTP ${r.status()}`);
    }
  } catch (e) {
    ko.push(`Sklep : parcours interrompu — ${e.message.slice(0, 70)}`);
  }

  await browser.close();

  const nbActions = inventaire.reduce((n, i) => n + i.actions.length, 0);
  console.log(`INVENTAIRE : ${nbActions} actions sur ${inventaire.length} écrans`);
  for (const i of inventaire) {
    if (i.actions.length) {
      const l = [...i.distincts].map(([t, n]) => (n > 1 ? `${t} ×${n}` : t)).join(' · ');
      console.log(`  ${i.ecran.padEnd(20)} ${l}`);
    }
  }
  console.log(`\nEXERCÉS (${ok.length})`);
  ok.forEach(x => console.log(`  ✓ ${x}`));
  console.log(`\nNON COUVERTS (${nonCouvert.length}) — ne comptent PAS pour verts`);
  nonCouvert.forEach(x => console.log(`  · ${x}`));
  console.log(`\nSOUCIS (${ko.length})`);
  console.log(ko.length ? ko.map(x => `  ✗ ${x}`).join('\n') : '  aucun');
  process.exit(ko.length ? 1 : 0);
})();
