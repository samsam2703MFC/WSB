// ============================================================================
//  audit-ui.js — on ouvre TOUT, on regarde TOUT, à deux largeurs.
//
//  POURQUOI CE FICHIER EXISTE. Les tests hors ligne prouvent les RÈGLES ;
//  ils ne voient pas une page qui déborde, un bouton de 33 px qu'un pouce
//  rate une fois sur trois, ni un champ muet pour un lecteur d'écran. Tout
//  ce qui a été trouvé de sérieux dans ce chantier l'a été en CONDUISANT
//  l'écran, pas en le relisant.
//
//  Ce qu'il cherche, dans l'ordre de ce que ça coûte :
//   1. une page qui ne s'affiche pas (500, navigation morte, erreur JS) ;
//   2. un lien mort dans la navigation — il envoie quelqu'un dans le mur ;
//   3. un débordement horizontal — sur un téléphone la moitié de l'écran
//      devient inatteignable, et ça ne se voit jamais depuis un bureau ;
//   4. une cible tactile sous 36 px ;
//   5. un champ sans étiquette — muet pour un lecteur d'écran ;
//   6. un texte collé à son bord (padding oublié).
//
//  CE QU'IL NE SIGNALE PAS, ET POURQUOI. Les liens en pleine phrase et les
//  codes de commande dans un tableau font 17 à 22 px : c'est de la
//  typographie, pas une cible de pouce. Les porter à 44 px doublerait la
//  hauteur de chaque tableau pour rendre un texte moins lisible. On ne
//  compte comme cible que ce qu'on VISE : boutons, pastilles, onglets.
//
//  UNE MISE EN GARDE QUI VAUT LE DÉTOUR. Une base fraîchement amorcée n'a
//  ni commande ni facture : les écrans s'ouvrent alors sur leur état vide,
//  l'audit les traverse en une seconde et les déclare bons — sans avoir
//  regardé une seule ligne de tableau. Il faut donc servir l'audit AVEC les
//  données de développement (php-api/data), sinon le vert ne prouve rien.
//
//  Usage :
//    cp -r mrszoko/backoffice/php-api/data <site déployé>/backoffice/api/data
//    php -S localhost:8093 -t <site déployé>   (php-api renommé en api)
//    node tools/audit-ui.js <cookie-de-session>
// ============================================================================
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const SESS = process.argv[2];
const BO = 'http://localhost:8093/backoffice';
const SHOP = 'http://localhost:8093/shop';

// La liste suit le rail (console_sections). Un écran livré et absent d'ici
// n'est jamais ouvert par personne avant un client — c'est exactement ainsi
// que le formulaire de contact est resté mort en production.
const ECRANS = [
  'pulpit.php', 'start.php',
  'zamowienia.php', 'subskrypcje.php', 'faktury.php', 'ksef.php', 'wysylka.php', 'zgloszenia.php',
  'klienci.php', 'kontrahenci.php', 'poczta.php', 'przypomnienia.php', 'kampanie.php',
  'produkty.php', 'magazyn.php', 'rabaty.php', 'tresci.php', 'allegro.php',
  'kraje.php', 'uzytkownicy.php', 'audyt.php', 'ustawienia.php',
];
const PAGES = ['/', '/?lang=en', '/?lang=uk', '/koszyk', '/kasa', '/kontakt', '/zamowienie/MS-TEST'];

// `pouce` : la règle des 36 px vise un DOIGT. Un lien de 25 px se clique
// très bien à la souris — le signaler au format bureau noie les vraies
// alertes sous vingt lignes qui ne demandent aucun travail. On ne mesure
// donc les cibles qu'à la largeur téléphone, qui est celle où ça compte.
const sonde = (pouce) => {
  const bad = [];
  const skip = el => {
    for (let a = el.parentElement; a; a = a.parentElement) {
      const o = getComputedStyle(a).overflowX;
      if (o === 'hidden' || o === 'auto' || o === 'scroll') return true;
    }
    return false;
  };
  // L'AUTORITÉ EST scrollWidth, PAS la position d'un élément. Un tiroir hors
  // écran à gauche (transform: translateX(-285px)) et un piège à robots posé
  // à left:-9999px dépassent tous deux la fenêtre — et ne font défiler RIEN
  // du tout : en écriture gauche-droite, le débordement négatif n'étend pas
  // scrollWidth. Une sonde qui les signale crie au loup, et on cesse de la
  // lire. On ne cherche donc les coupables QUE si la page défile vraiment.
  if (document.documentElement.scrollWidth > window.innerWidth + 1) {
    document.querySelectorAll('*').forEach(el => {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > window.innerWidth + 1 && !skip(el)) {
        bad.push(el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ')[0] : ''));
      }
    });
  }

  // Cibles tactiles : ce qu'on VISE avec un pouce.
  //
  // Un `<a>` n'est pas une cible du seul fait d'être cliquable. « Pulpit »
  // dans un fil d'Ariane, un nom de produit dans une phrase et une adresse
  // e-mail dans un tableau font 16 à 22 px : c'est de la TYPOGRAPHIE. Les
  // porter à 44 px doublerait la hauteur de chaque tableau pour rendre le
  // texte moins lisible.
  //
  // Le critère retenu : un lien compte comme cible quand on lui a donné une
  // SURFACE — une bordure, un fond, ou du rembourrage. C'est ce qui
  // distingue un bouton d'un mot souligné, et ça se lit dans le style
  // calculé plutôt que dans une liste de classes à tenir à jour écran par
  // écran.
  //
  // ATTENTION AU PIÈGE : `display` ne suffit PAS. Un fil d'Ariane est un
  // conteneur flex, donc ses liens sont « blockifiés » et paraissent dessinés
  // alors qu'ils ne sont que du texte. C'est ce qui faisait signaler le fil
  // d'Ariane sur chacun des vingt et un écrans : vingt et une fausses alertes
  // qui enterrent la vraie, et on cesse de lire le rapport — exactement la
  // panne que ce fichier existe pour éviter.
  const dessine = (el, s) => {
    if (el.tagName !== 'A') return true;                  // bouton, select, [role=button]
    if (el.getAttribute('role') === 'button') return true;
    if (parseFloat(s.borderTopWidth) > 0 || parseFloat(s.borderBottomWidth) > 0) return true;
    const bg = s.backgroundColor || '';
    if (bg && bg !== 'transparent' && !/rgba\(0,\s*0,\s*0,\s*0\)/.test(bg)) return true;
    return parseFloat(s.paddingLeft) >= 6 || parseFloat(s.paddingTop) >= 6;
  };

  const petits = [];
  document.querySelectorAll('button, a[href], input[type=submit], select, [role=button]').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;                 // caché : pas une cible
    const s = getComputedStyle(el);
    if (s.display === 'contents') return;
    if (!dessine(el, s)) return;
    if (pouce && r.height < 36) {
      const t = (el.textContent || el.value || el.getAttribute('aria-label') || '').trim().slice(0, 22);
      petits.push(`${el.tagName.toLowerCase()}[${t}] h=${Math.round(r.height)}`);
    }
  });

  // Champs sans étiquette : muets pour un lecteur d'écran.
  const muets = [];
  document.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width === 0) return;
    const id = el.id;
    const parLabel = id && document.querySelector(`label[for="${CSS.escape(id)}"]`);
    const dansLabel = el.closest('label');
    const aria = el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
    const ph = el.getAttribute('placeholder');
    if (!parLabel && !dansLabel && !aria && !ph) {
      muets.push(`${el.tagName.toLowerCase()}[name=${el.name || '?'}]`);
    }
  });

  // Texte collé au bord : un padding oublié se voit à ça.
  const colles = [];
  document.querySelectorAll('main, .panel, section, aside, .card, .kpi').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width < 60 || r.height < 30) return;
    const s = getComputedStyle(el);
    const pl = parseFloat(s.paddingLeft), pr = parseFloat(s.paddingRight);
    const aDuTexte = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim().length > 3);
    if (aDuTexte && (pl < 4 || pr < 4)) {
      colles.push(`${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0]} pl=${pl} pr=${pr}`);
    }
  });

  return {
    larg: document.documentElement.scrollWidth, vue: window.innerWidth,
    deborde: [...new Set(bad)].slice(0, 5),
    petits: [...new Set(petits)].slice(0, 5),
    muets: [...new Set(muets)].slice(0, 5),
    colles: [...new Set(colles)].slice(0, 3),
    titre: (document.querySelector('h1')?.textContent || '').trim().slice(0, 40),
    liens: [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href'))
             .filter(h => h && !h.startsWith('#') && !h.startsWith('mailto:') && !h.startsWith('http')),
  };
};

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const rapport = [];
  const soucis = [];

  for (const [w, h, nom] of [[1400, 900, 'bureau'], [390, 844, 'telephone']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, locale: 'pl-PL' });
    await ctx.addCookies([{ name: 'WSMSESS', value: SESS, domain: 'localhost', path: '/' }]);
    const p = await ctx.newPage();
    const erreurs = [];
    p.on('pageerror', e => erreurs.push(e.message.slice(0, 100)));
    p.on('console', m => { if (m.type() === 'error' && !m.text().includes('favicon')) erreurs.push('console: ' + m.text().slice(0, 90)); });

    for (const cible of [...ECRANS.map(e => [`${BO}/${e}`, e, 'konsola']),
                         ...PAGES.map(x => [`${SHOP}${x}`, x, 'sklep'])]) {
      const [url, label, ou] = cible;
      erreurs.length = 0;
      let code = 0;
      try {
        const resp = await p.goto(url, { waitUntil: 'load', timeout: 30000 });
        code = resp ? resp.status() : 0;
      } catch (e) { soucis.push(`[${nom}] ${label} : NAVIGATION ÉCHOUE — ${e.message.slice(0, 70)}`); continue; }

      if (code >= 500) { soucis.push(`[${nom}] ${label} : HTTP ${code}`); continue; }
      await p.waitForTimeout(250);
      const s = await p.evaluate(sonde, nom === 'telephone');
      const l = [];
      if (s.deborde.length) { l.push(`déborde: ${s.deborde.join(', ')}`); soucis.push(`[${nom}] ${label} : débordement horizontal — ${s.deborde.join(', ')}`); }
      if (s.petits.length) { l.push(`cibles<36: ${s.petits.join(' | ')}`); soucis.push(`[${nom}] ${label} : cible tactile trop petite — ${s.petits.join(' | ')}`); }
      if (s.muets.length) { l.push(`sans label: ${s.muets.join(', ')}`); soucis.push(`[${nom}] ${label} : champ sans étiquette — ${s.muets.join(', ')}`); }
      if (s.colles.length) { l.push(`sans padding: ${s.colles.join(', ')}`); soucis.push(`[${nom}] ${label} : texte collé au bord — ${s.colles.join(', ')}`); }
      // Une page qui répond 404 fait journaliser son propre document comme
      // « ressource non chargée ». Ce n'est pas une erreur de la page : c'est
      // la page qui fait son travail. `/zamowienie/MS-TEST` n'existe pas, et
      // doit répondre 404 — le compter comme souci à chaque passage, c'est
      // remettre une ligne rouge permanente dans un rapport dont l'intérêt
      // est justement d'être vide quand tout va bien.
      const vraies = code === 404
        ? erreurs.filter(e => !e.includes('Failed to load resource'))
        : erreurs;
      if (vraies.length) { l.push(`JS: ${vraies[0]}`); soucis.push(`[${nom}] ${label} : erreur JS — ${vraies[0]}`); }
      rapport.push(`[${nom}] ${ou}/${label} ${code} « ${s.titre} » ${l.length ? '→ ' + l.join(' ; ') : 'OK'}`);
    }
    await ctx.close();
  }

  // Les liens de la navigation doivent tous répondre.
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
  await ctx.addCookies([{ name: 'WSMSESS', value: SESS, domain: 'localhost', path: '/' }]);
  const p = await ctx.newPage();
  await p.goto(`${BO}/pulpit.php`, { waitUntil: 'domcontentloaded' });
  const nav = await p.evaluate(() => [...document.querySelectorAll('nav.menu a')].map(a => a.getAttribute('href')));
  for (const href of nav) {
    if (!href || href.startsWith('http')) continue;
    const u = href.startsWith('/') ? `http://localhost:8093${href}` : `${BO}/${href}`;
    const r = await p.request.get(u).catch(() => null);
    const st = r ? r.status() : 0;
    if (st === 0 || st >= 400) soucis.push(`[nav] ${href} → ${st}`);
    rapport.push(`[nav] ${href} → ${st}`);
  }
  await ctx.close();
  await browser.close();

  console.log(rapport.join('\n'));
  console.log('\n================ SOUCIS ================');
  console.log(soucis.length ? soucis.join('\n') : 'aucun');
})();
