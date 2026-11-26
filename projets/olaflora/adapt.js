/* adapt.js — Ola flora : relie les écrans du projet (tels quels) aux tables SQL.
   Chargé en tête de page, AVANT support.js. Rien n'est réécrit dans les
   fichiers : au chargement seulement,
   1. les données de l'écran sont lues sur le serveur (synchrone) ;
   2. le code de l'écran est retouché en mémoire : chaque champ fourni par le
      serveur (ex. ZAP, DOSS) prend la valeur de la base au lieu de l'exemple
      de la maquette ; une couche glissée sous la classe signale les gestes
      (ex. « Wysyłam » du formulaire, « Publikuj na żywo » du CMS) pour les
      enregistrer ;
   3. CMS de la page publique : la page « Ola flora - wesela v2 » reçoit les
      textes et photos PUBLIÉS (of_page_sections) ; le back office (écran
      « strony ») édite exactement ces textes et photos, relus dans le gabarit
      de la page publique elle-même — une seule règle (ofCms) des deux côtés. */
(function () {
  var nom = decodeURIComponent(location.pathname.split('/').pop() || '');
  var ekran = (nom === '' || nom === 'index.html') ? 'Ola flora - wesela v2' : nom.replace(/\.dc\.html$/, '');
  var prive = /^(Back office|Poczta|Super admin|Asystent rozmowy|Retroplan|Karta misji)/.test(ekran);
  var publiczna = ekran === 'Ola flora - wesela v2';
  var STRONA = 'Ola flora - wesela v2.dc.html';
  var base = (document.currentScript && document.currentScript.src) ? document.currentScript.src.replace(/adapt\.js(\?.*)?$/, '') : '_wsb/';
  var hasOwn = function (o, k) { return Object.prototype.hasOwnProperty.call(o, k); };

  function pobierz(url) {
    try {
      var x = new XMLHttpRequest();
      x.open('GET', url, false);
      x.send(null);
      if (x.status === 200) return x.responseText;
    } catch (e) { /* hors ligne : les exemples de la maquette restent */ }
    return null;
  }
  function json(t) { try { return JSON.parse(t || '{}') || {}; } catch (e) { return {}; } }

  var formularz = ekran === 'FormularzWizard';
  // La langue de la page publique et du formulaire : ?lang=en|de|pl, puis le choix gardé
  // (cookie — pas localStorage, que stockage.js envoie au serveur), sinon le polonais.
  var JEZYKI = ['pl', 'en', 'de'];
  var jezyk = (function () {
    var q = (/[?&]lang=(pl|en|de)\b/.exec(location.search) || [])[1];
    if (q) { try { document.cookie = 'olaflora_lang=' + q + '; path=/; max-age=31536000; SameSite=Lax'; } catch (e) {} }
    else q = (/(?:^|; )olaflora_lang=(pl|en|de)/.exec(document.cookie) || [])[1];
    return JEZYKI.indexOf(q) >= 0 ? q : 'pl';
  })();
  if (!publiczna && !formularz) jezyk = 'pl';

  var dane = prive ? json(pobierz(base + 'prive/app/dane.php?ekran=' + encodeURIComponent(ekran))) : {};
  // { t: {k: tekst PL}, tl: {k: tekst de la langue}, z: {k: [src]}, alt, kat, slownik: {PL: langue} }
  var cmsPub = (publiczna || formularz) ? json(pobierz(base + 'app/strona.php?lang=' + jezyk)) : null;
  if (publiczna || formularz) document.documentElement.lang = jezyk;
  var SL = (cmsPub && cmsPub.slownik) || {};
  window.__of_dane = dane;

  // Un texte polonais dans la langue de la page (dictionnaire relu) ; inconnu = tel quel.
  function tr(s) {
    if (jezyk === 'pl' || s == null) return s;
    var x = String(s);
    if (hasOwn(SL, x)) return SL[x];
    var n = x.replace(/\s+/g, ' ').trim();
    if (n !== x && hasOwn(SL, n)) { var m = /^(\s*)[\s\S]*?(\s*)$/.exec(x); return m[1] + SL[n] + m[2]; }
    return x;
  }

  function poster(url, corps, ok) {
    var y = new XMLHttpRequest();
    y.open('POST', base + url, true);
    y.setRequestHeader('Content-Type', 'application/json');
    y.onload = function () { var r = {}; try { r = JSON.parse(y.responseText || '{}'); } catch (e) {} ok && ok(r, y.status); };
    y.onerror = function () { ok && ok({ error: 'siec' }, 0); };
    y.send(JSON.stringify(corps));
  }

  /* ------------------------------------------------------------------ */
  /* ofCms — la règle commune : quels textes / photos du gabarit public   */
  /* sont éditables, et sous quelle clé.                                   */
  /* ------------------------------------------------------------------ */
  var ofCms = (function () {
    var ZAKLADKI = { isHome: 'home', isOferta: 'oferta', isOMnie: 'omnie', isPortfolio: 'portfolio', isKontakt: 'kontakt' };
    var ROLA = { h1: 'Tytuł', h2: 'Nagłówek', h3: 'Nagłówek', p: 'Akapit', button: 'Przycisk', a: 'Link', strong: 'Wyróżnienie', em: 'Kursywa', span: 'Tekst', div: 'Tekst', li: 'Punkt' };
    function fnv(s) {
      var h = 0x811c9dc5;
      for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0; }
      return ('0000000' + h.toString(16)).slice(-8);
    }
    function norm(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }
    function strona(el) {
      for (var n = el; n; n = n.parentNode) {
        if (n.nodeType === 1 && n.tagName && n.tagName.toLowerCase() === 'sc-if') {
          var m = /\{\{\s*(\w+)\s*\}\}/.exec(n.getAttribute('value') || '');
          if (m && ZAKLADKI[m[1]]) return ZAKLADKI[m[1]];
          if (m && m[1] === 'lbOpen') return null;     // visionneuse : rien à éditer
        }
      }
      return 'wspolne';
    }
    function wPomijanym(el) {
      for (var n = el; n; n = n.parentNode) {
        if (n.nodeType === 1 && n.tagName && /^(style|script|helmet|sc-for)$/i.test(n.tagName)) return true;
        if (n.nodeType === 1 && n.getAttribute && n.getAttribute('data-of-cms') === 'pomin') return true;   // rempli par la liste « sezon »
      }
      return false;
    }
    function strefa(el) {
      for (var n = el; n; n = n.parentNode) {
        var t = n.nodeType === 1 && n.tagName ? n.tagName.toLowerCase() : '';
        if (t === 'header' || t === 'nav') return 'Menu';
        if (t === 'footer') return 'Stopka';
      }
      return '';
    }
    function rodzajLinku(href) {
      return /^mailto:/i.test(href) ? 'e-mail' : /^tel:/i.test(href) ? 'telefon' : /instagram/i.test(href) ? 'Instagram' : /facebook/i.test(href) ? 'Facebook' : 'link';
    }
    function wLinku(el) {
      for (var n = el; n; n = n.parentNode) if (n.nodeType === 1 && n.tagName && n.tagName.toLowerCase() === 'a' && n.getAttribute('href')) return rodzajLinku(n.getAttribute('href'));
      return '';
    }
    function szablon(html) { var t = document.createElement('template'); t.innerHTML = html; return t; }

    // Parcourt le gabarit ; rappel(item, noeud) pour chaque élément éditable, dans l'ordre de la page.
    function przejdz(t, rappel) {
      var licz = {}, kontekst = {};
      var it = document.createTreeWalker(t.content, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
      var n;
      function klucz(page, rodzaj, tresc) {
        var k = page + ':' + rodzaj + fnv(tresc);
        licz[k] = (licz[k] || 0) + 1;
        return licz[k] > 1 ? k + '.' + licz[k] : k;
      }
      while ((n = it.nextNode())) {
        if (wPomijanym(n.nodeType === 3 ? n.parentNode : n)) continue;
        var page = strona(n.nodeType === 3 ? n.parentNode : n);
        if (!page) continue;
        if (n.nodeType === 3) {
          var txt = norm(n.nodeValue);
          if (!txt || txt.indexOf('{{') >= 0 || !/[0-9A-Za-zÀ-ž]/.test(txt)) continue;
          var tag = n.parentNode.tagName.toLowerCase();
          if (/^h[1-3]$/.test(tag)) kontekst[page] = txt;
          if (/^\d{1,2}$/.test(txt)) continue;          // numéros d'étapes (01, 02…) : pas des textes
          var lk = wLinku(n.parentNode);
          rappel({ k: klucz(page, 't', txt), page: page, kind: 'tekst', rola: lk ? lk + ' — tekst' : (ROLA[tag] || 'Tekst'), tag: tag, def: txt, ctx: kontekst[page] || '', strefa: strefa(n.parentNode) }, n);
        } else {
          var e = n, t2 = e.tagName.toLowerCase();
          if (t2 === 'img') {
            var src = e.getAttribute('src') || '';
            if (!src || src.indexOf('{{') >= 0 || /logotyp|logo-/.test(src)) continue;
            rappel({ k: klucz(page, 'i', src), page: page, kind: 'zdjęcie', rola: 'Zdjęcie', tag: 'img', def: src, alt: e.getAttribute('alt') || '', ctx: kontekst[page] || '', strefa: strefa(e) }, e);
          } else if (t2 === 'a') {
            var href = e.getAttribute('href') || '';
            if (!href || href.indexOf('{{') >= 0 || href.charAt(0) === '#') continue;
            rappel({ k: klucz(page, 'a', href), page: page, kind: 'link', rola: rodzajLinku(href) + ' — adres', tag: 'a', def: href, ctx: kontekst[page] || '', strefa: strefa(e) }, e);
          }
        }
      }
    }

    /* « Sezonowo w sklepie » (offre, maquette B) : un bandeau glissé après « 05 Kwiaty ».
       Une ligne par saison dans la section « oferta:sezon » : nazwa | od (dd.mm) | do (dd.mm) | link.
       Une saison n'est visible qu'entre ses dates ET avec un lien ; sans saison visible, pas de bandeau.
       Les cartes sont TOUJOURS glissées (cachées au besoin) : les clés des photos restent les mêmes. */
    var SEZON_ZDJ = ['assets/photos/ola/firmy-recepcja-ostrokrzew.jpg', 'assets/photos/ola/firmy-hortensje-instalacja.jpg'];
    var MIESIACE = { pl: ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'],
                     en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                     de: ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'] };
    var MIES = MIESIACE[jezyk] || MIESIACE.pl;
    // Lien vers la boutique dans la langue de la page (la boutique parle pl / en / de).
    function linkSklep(l) {
      if (!l || jezyk === 'pl' || /[?&]lang=/.test(l)) return l;
      var h = l.indexOf('#'), a = h >= 0 ? l.slice(0, h) : l, b = h >= 0 ? l.slice(h) : '';
      return a + (a.indexOf('?') >= 0 ? '&' : '?') + 'lang=' + jezyk + b;
    }
    function sezony(tekst) {
      return String(tekst || '').split(/\r?\n/).map(function (l) { return l.split('|').map(function (x) { return x.trim(); }); })
        .filter(function (c) { return c[0]; })
        .map(function (c) {
          var od = /^(\d{1,2})\.(\d{1,2})/.exec(c[1] || ''), dl = /^(\d{1,2})\.(\d{1,2})/.exec(c[2] || '');
          var md = function (m) { return m ? (+m[2]) * 100 + (+m[1]) : null; };
          var et = function (m) { return m ? (+m[1]) + ' ' + (MIES[+m[2] - 1] || '') : ''; };
          var a = md(od), b = md(dl), d = new Date(), dz = (d.getMonth() + 1) * 100 + d.getDate();
          var teraz = a === null || b === null ? true : (a <= b ? dz >= a && dz <= b : dz >= a || dz <= b);
          var link = /^(https?:\/\/|\/|[a-z0-9_-]+\/)/i.test(c[3] || '') && !/^javascript:/i.test(c[3]) ? c[3] : '';
          return { nazwa: tr(c[0]), okres: et(od) && et(dl) ? (od[2] === dl[2] ? (+od[1]) + ' – ' + et(dl) : et(od) + ' – ' + et(dl)) : '', teraz: teraz, link: linkSklep(link) };
        });
    }
    function esc(x) { return String(x).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function wstawSezon(t, tekst, wszystko) {
      var h = [].slice.call(t.content.querySelectorAll('h2')).filter(function (x) { return norm(x.textContent) === 'Kwiaty' && strona(x) === 'oferta'; })[0];
      var sek = h && h.closest('section');
      if (!sek) return false;
      var lista = sezony(tekst), widac = 0;
      var karty = [0, 1].map(function (i) {
        var z = lista[i] || { nazwa: '', okres: '', teraz: false, link: '' };
        var on = wszystko || (z.teraz && z.link);
        if (on) widac++;
        return '<a href="' + esc(z.link || '#') + '" style="' + (on ? 'display: flex;' : 'display: none;') + ' flex-direction: column; gap: 14px; color: #f4efe7;">' +
          '<div style="position: relative; aspect-ratio: 4 / 3; overflow: hidden; background: #4a423a;"><img src="' + SEZON_ZDJ[i] + '" alt="" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block;"></div>' +
          '<div data-of-cms="pomin" style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px;"><span style="font-family: var(--font-display); font-size: 1.8rem; font-weight: 300;">' + esc(z.nazwa) + '</span>' +
          '<span style="font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #c9b99f; white-space: nowrap;">' + esc(z.okres) + '</span></div>' +
          '<span data-of-cms="pomin" style="font-size: 12px; letter-spacing: 0.18em; text-transform: uppercase; border-bottom: 1px solid #c9b99f; align-self: flex-start; padding-bottom: 4px;">' + esc(tr('w sklepie →')) + '</span></a>';
      });
      var w = document.createElement('section');
      w.setAttribute('style', 'background: #2e2924; color: #ebe3d6;' + (widac ? '' : ' display: none;'));
      w.innerHTML = '<div style="max-width: 1240px; margin: 0 auto; padding: clamp(56px, 7vw, 96px) 28px; display: flex; flex-direction: column; gap: 40px;">' +
        '<div style="display: flex; flex-wrap: wrap; gap: 24px 64px; align-items: flex-end;"><div style="flex: 1 1 320px;">' +
        '<span style="font-family: var(--font-display); font-style: italic; font-size: 1.5rem; color: #c9b99f;">sezonowo</span>' +
        '<h2 style="margin: 8px 0 0; font-family: var(--font-display); font-weight: 300; font-size: clamp(2.2rem, 4vw, 3.2rem); line-height: 1.08; color: #f4efe7;">Do zamówienia w sklepie</h2></div>' +
        '<p style="flex: 1.2 1 380px; margin: 0; color: #d8cdbd; max-width: 56ch;">Dekoracje świąteczne i wianki zamawiacie w sklepie internetowym — z odbiorem w pracowni lub dostawą.</p></div>' +
        '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 440px), 1fr)); gap: 28px;">' + karty.join('') + '</div></div>';
      sek.after(w);
      return widac > 0;
    }
    function wstawJezyki(t) {
      var nav = t.content.querySelector('header nav');
      if (!nav) return false;
      var sp = document.createElement('span');
      sp.setAttribute('data-of-cms', 'pomin');
      sp.setAttribute('style', 'flex: 0 0 auto; display: flex; gap: 12px; margin-left: 6px; padding-left: 14px; border-left: 1px solid #d8cdbd;');
      sp.innerHTML = JEZYKI.map(function (j) {
        return '<a href="?lang=' + j + '" data-of-lang="' + j + '" hreflang="' + j + '" style="font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; padding: 4px 0; color: #2e2924; border-bottom: 1px solid ' + (j === jezyk ? '#2e2924' : 'transparent') + ';">' + j + '</a>';
      }).join('');
      nav.appendChild(sp);
      // LE LIEN VERS LA BOUTIQUE, toujours dans l'en-tête (pas seulement dans le bandeau
      // de saison, au milieu de la page) : juste avant le bouton « formularz ».
      var cta = null, bs = nav.querySelectorAll('button');
      for (var i = 0; i < bs.length; i++) if (/goForm/.test(bs[i].getAttribute('onClick') || '')) cta = bs[i];
      var a = document.createElement('a');
      a.setAttribute('href', linkSklep('sklep/'));
      a.setAttribute('data-of-cms', 'pomin');
      a.setAttribute('data-of-sklep', '1');
      a.setAttribute('style', 'flex: 0 0 auto; display: inline-flex; align-items: center; gap: 8px; padding: 4px 0; font-family: var(--font-body); font-weight: 400; font-size: 12px; letter-spacing: 0.18em; text-transform: uppercase; white-space: nowrap; color: #465802; text-decoration: none; border-bottom: 1px solid #465802;');
      a.innerHTML = '<span aria-hidden="true" style="width: 6px; height: 6px; background: #465802; display: inline-block;"></span>' + ({ pl: 'Sklep', en: 'Shop', de: 'Shop' }[jezyk] || 'Sklep');
      if (cta) nav.insertBefore(a, cta); else nav.insertBefore(a, sp);
      // Au téléphone le menu défile de côté : le lien y serait caché. Un bouton à part,
      // à côté du logo, le remplace (styles dans ofStylesSklep).
      var box = nav.parentNode, logo = box && box.querySelector('button');
      if (box && logo) {
        var m = a.cloneNode(true);
        m.removeAttribute('data-of-sklep'); m.setAttribute('data-of-sklep-m', '1'); m.setAttribute('class', 'of-sklep-m');
        m.setAttribute('style', 'flex: 0 0 auto; align-items: center; gap: 7px; padding: 7px 11px; border: 1px solid #465802; font-family: var(--font-body); font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase; color: #465802; text-decoration: none; white-space: nowrap;');
        box.insertBefore(m, logo.nextSibling);
      }
      return true;
    }
    (function ofStylesSklep() {
      if (document.getElementById('of-sklep-css')) return;
      var st = document.createElement('style'); st.id = 'of-sklep-css';
      st.textContent = '.of-sklep-m{display:none}@media (max-width:760px){.of-sklep-m{display:inline-flex}a[data-of-sklep]{display:none!important}}';
      (document.head || document.documentElement).appendChild(st);
    })();
    var SEZON_DOMYSLNY = 'Dekoracje świąteczne | 01.11 | 20.12 | sklep/#katalog\nWianki | 01.11 | 31.12 | sklep/#katalog';

    // Tout texte et toute étiquette (placeholder, title, alt) d'un gabarit, dans la langue.
    function tlumaczSzablon(html) {
      if (jezyk === 'pl') return html;
      var t = szablon(html), it = document.createTreeWalker(t.content, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT), n;
      while ((n = it.nextNode())) {
        if (n.nodeType === 3) {
          if (wPomijanym(n.parentNode) || n.nodeValue.indexOf('{{') >= 0 || !n.nodeValue.trim()) continue;
          n.nodeValue = tr(n.nodeValue);
        } else ['placeholder', 'title', 'alt'].forEach(function (a) {
          var v = n.getAttribute(a); if (v && v.indexOf('{{') < 0) n.setAttribute(a, tr(v));
        });
      }
      return t.innerHTML;
    }
    // Les chaînes du code ('…') dans la langue — jamais un identifiant : ni après « id: »,
    // « key: », ni dans une comparaison (=== / !==), que le serveur et la logique lisent.
    function tlumaczJs(js) {
      if (jezyk === 'pl') return js;
      return js.replace(/'((?:[^'\\\n]|\\.)*)'/g, function (cale, w, off) {
        var przed = js.slice(Math.max(0, off - 16), off);
        if (/\b(id|key)\s*:\s*$/.test(przed) || /[!=]==?\s*$/.test(przed)) return cale;
        var raw = w.replace(/\\(.)/g, '$1'), x = tr(raw);
        return x === raw ? cale : "'" + x.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
      });
    }

    function wyciagnij(html) { var out = [], t = szablon(html); wstawSezon(t, SEZON_DOMYSLNY, true); przejdz(t, function (i) { out.push(i); }); return out; }

    // Remplace dans le gabarit ce qui a été publié. pub = { t: {k: tekst}, z: {k: [src]}, alt: {src: alt} }
    function zastosuj(html, pub) {
      pub = pub || {};
      var t = szablon(html), T = pub.t || {}, TL = pub.tl || {}, Z = pub.z || {}, A = pub.alt || {}, zmiana = false;
      var pelny = function (o, k) { return hasOwn(o, k) && String(o[k]).trim() !== ''; };
      var sez = jezyk !== 'pl' && pelny(TL, 'oferta:sezon') ? TL['oferta:sezon'] : (pelny(T, 'oferta:sezon') ? T['oferta:sezon'] : SEZON_DOMYSLNY);
      zmiana = wstawSezon(t, sez, false);
      zmiana = wstawJezyki(t) || zmiana;
      przejdz(t, function (i, n) {
        if (i.kind === 'tekst') {
          // PL : le publié, sinon le gabarit. EN / DE : la section de la langue, sinon la traduction
          // du texte polonais publié, puis du texte d'origine, sinon le polonais.
          var v = null;
          if (jezyk === 'pl') { if (pelny(T, i.k)) v = T[i.k]; }
          else if (pelny(TL, i.k)) v = TL[i.k];
          else if (pelny(T, i.k) && hasOwn(SL, norm(T[i.k]))) v = SL[norm(T[i.k])];
          else if (hasOwn(SL, i.def)) v = SL[i.def];
          else if (pelny(T, i.k)) v = T[i.k];
          if (v !== null) { var m = /^(\s*)[\s\S]*?(\s*)$/.exec(n.nodeValue); n.nodeValue = m[1] + v + m[2]; zmiana = true; }
        } else if (i.kind === 'zdjęcie') {
          if (hasOwn(Z, i.k) && Z[i.k] && Z[i.k][0]) {
            var src = Z[i.k][0];
            n.setAttribute('src', src);
            if (A[src]) n.setAttribute('alt', A[src]);
            n.removeAttribute('ref');          // le héros était forcé par le réglage « hero » : la photo publiée l'emporte
            zmiana = true;
          }
          if (jezyk !== 'pl' && n.getAttribute('alt')) { n.setAttribute('alt', tr(n.getAttribute('alt'))); zmiana = true; }
        } else if (i.kind === 'link' && hasOwn(T, i.k) && /^(https?:\/\/|mailto:|tel:)\S+$/i.test(String(T[i.k]).trim())) {
          n.setAttribute('href', String(T[i.k]).trim()); zmiana = true;
        }
      });
      return zmiana ? t.innerHTML : html;
    }

    // Champ de classe « NOM = … » du code d'un écran : [début de l'expression, fin].
    function pole(js, nazwa) {
      var m = new RegExp('^(\\s{2})' + nazwa + '\\s*=\\s*', 'm').exec(js);
      if (!m) return null;
      var i = m.index + m[0].length, d = 0, q = '', n = js.length;
      for (; i < n; i++) {
        var c = js[i];
        if (q) { if (c === '\\') { i++; continue; } if (c === q) q = ''; continue; }
        if (c === '"' || c === "'" || c === '`') { q = c; continue; }
        if (c === '/' && js[i + 1] === '/') { while (i < n && js[i] !== '\n') i++; i--; continue; }
        if (c === '(' || c === '[' || c === '{') d++;
        else if (c === ')' || c === ']' || c === '}') d--;
        else if (c === ';' && d === 0) break;
        else if (c === '\n' && d === 0) {
          var reste = js.slice(i + 1).match(/^\s*(\S)/);
          if (!reste || reste[1] !== '.') break;
        }
      }
      return [m.index + m[0].length, i];
    }
    function wartoscPola(js, nazwa) {
      var r = pole(js, nazwa);
      if (!r) return undefined;
      try { return (new Function('return (' + js.slice(r[0], r[1]) + ');'))(); } catch (e) { return undefined; }
    }
    function owin(js, nazwa, fn) {   // « NOM = expr » → « NOM = window[fn]("NOM", (expr)) »
      var r = pole(js, nazwa);
      if (!r) return js;
      return js.slice(0, r[0]) + 'window.' + fn + '("' + nazwa + '", (' + js.slice(r[0], r[1]) + '))' + js.slice(r[1]);
    }
    function wycinek(t) { var a = t.indexOf('<x-dc>'), b = t.lastIndexOf('</x-dc>'); return a >= 0 && b > a ? [a + 6, b] : null; }
    function nazwaPliku(src) {
      var m = /zdjecie\.php\?id=(\d+)/.exec(src); if (m) return 'wgrane-' + m[1];
      return String(src).split('/').pop().replace(/\.[a-z0-9]+$/i, '');
    }
    return { tlumaczSzablon: tlumaczSzablon, tlumaczJs: tlumaczJs, SEZON_DOMYSLNY: SEZON_DOMYSLNY, wyciagnij: wyciagnij, zastosuj: zastosuj, pole: pole, wartoscPola: wartoscPola, owin: owin, wycinek: wycinek, nazwaPliku: nazwaPliku, norm: norm };
  })();
  window.__ofCms = ofCms;
  window.__ofPustyZap = { id: '', iso: '', who: '', when: '', venue: '', ceremony: '', guests: '', budget: '', style: '', palette: '',
    tables: '', head: '', extras: '', mail: '', tel: '', ago: '', state: '', day: [] };

  // Le runtime relit la page brute (fetch(location.href)) pour son gabarit : on lui rend la même version retouchée.
  // Le runtime relit la page brute (fetch(location.href)) pour son gabarit, et va chercher
  // chaque composant embarqué (<dc-import name="FormularzWizard">) par fetch : on lui rend
  // la même version retouchée — gabarit dans la langue, code avec ses gestes enregistrés.
  function poprawKomponent(t, nazwa) {
    var w = ofCms.wycinek(t);
    if (w) t = t.slice(0, w[0]) + ofCms.tlumaczSzablon(t.slice(w[0], w[1])) + t.slice(w[1]);
    return t.replace(/(<script type="text\/x-dc" data-dc-script[^>]*>)([\s\S]*?)(<\/script>)/, function (c, a, js, z) {
      js = ofCms.tlumaczJs(js).replace(/class\s+Component\s+extends\s+DCLogic\b/, 'class Component extends window.__ofKlasa(DCLogic, ' + JSON.stringify(nazwa) + ')');
      return a + js + z;
    });
  }
  function przechwycFetch(popraw, komponenty) {
    var orig = window.fetch;
    if (!orig) return;
    var ici = location.href.split('#')[0];
    window.fetch = function (u, o) {
      var adr = typeof u === 'string' ? u : (u && u.url) || '';
      var abs = ''; try { abs = new URL(adr, location.href).href.split('#')[0].split('?')[0]; } catch (e) {}
      var plik = decodeURIComponent(abs.split('/').pop() || '');
      var nazwa = plik.replace(/\.dc\.html$/, '');
      var tu = abs === ici.split('?')[0];
      if (!(tu && popraw) && !(komponenty && komponenty.indexOf(nazwa) >= 0 && /\.dc\.html$/.test(plik))) return orig.apply(this, arguments);
      return orig.apply(this, arguments).then(function (res) {
        if (!res.ok) return res;
        return res.text().then(function (t) {
          if (tu) { var w = ofCms.wycinek(t); if (w) t = t.slice(0, w[0]) + popraw(t.slice(w[0], w[1])) + t.slice(w[1]); }
          else t = poprawKomponent(t, nazwa);
          return new Response(t, { status: res.status, statusText: res.statusText, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
        });
      });
    };
  }

  /* ------------------------------------------------------------------ */
  /* Page publique : données des listes (menu, galerie, pasek) publiées   */
  /* ------------------------------------------------------------------ */
  function linie(v) { return String(v || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean); }
  // Nom de photo de la maquette du back office (relatif à assets/photos/) → adresse sur la page publique.
  function srcZ(f) { f = String(f || ''); return f.indexOf('../../') === 0 ? f.slice(6) : 'assets/photos/' + f; }

  function listyPubliczne(js, pub) {
    var T = (pub && pub.t) || {}, TL = (pub && pub.tl) || {}, Z = (pub && pub.z) || {}, A = (pub && pub.alt) || {}, K = (pub && pub.kat) || {}, o = {};
    var TABS = ofCms.wartoscPola(js, 'TABS'), CATS = ofCms.wartoscPola(js, 'CATS'), P = ofCms.wartoscPola(js, 'P');
    var pelny = function (x, k) { return hasOwn(x, k) && String(x[k]).trim() !== ''; };
    var obca = jezyk !== 'pl';
    // Le texte d'une liste : celui de la langue (déjà traduit), sinon le polonais publié (à traduire).
    var zrodlo = function (k) {
      if (obca && pelny(TL, k)) return { txt: TL[k], gotowy: true };
      if (pelny(T, k)) return { txt: T[k], gotowy: !obca };
      return null;
    };
    var etykiety = function (lista, k) {
      var z = zrodlo(k), l = z ? linie(z.txt) : [];
      return lista.map(function (x, i) { return { id: x.id, label: l[i] ? (z.gotowy ? l[i] : tr(l[i])) : tr(x.label) }; });
    };
    if (TABS && (obca || zrodlo('wspolne:menu'))) o.TABS = etykiety(TABS, 'wspolne:menu');
    if (CATS && (obca || zrodlo('portfolio:kategorie'))) o.CATS = etykiety(CATS, 'portfolio:kategorie');
    if (P && (obca || Z['portfolio:galeria'] || zrodlo('portfolio:podpisy'))) {
      var opis = {}, zp = zrodlo('portfolio:podpisy');
      if (zp) linie(zp.txt).forEach(function (s) {
        var p = s.split('|').map(function (x) { return x.trim(); });
        if (p[0]) opis[p[0]] = { a: p[1] ? (zp.gotowy ? p[1] : tr(p[1])) : '', c: p[2] || '' };
      });
      var przezNazwe = {}; P.forEach(function (p) { przezNazwe[ofCms.nazwaPliku(p.src)] = p; });
      var lista = Z['portfolio:galeria'] ? Z['portfolio:galeria'] : P.map(function (p) { return p.src; });
      o.P = lista.map(function (src) {
        var nz = ofCms.nazwaPliku(src), d = przezNazwe[nz] || {}, op = opis[nz] || {};
        return { src: src, a: op.a || tr(d.a || A[src] || nz.replace(/-/g, ' ')), c: op.c || d.c || K[src] || '', sc: d.sc || 1 };
      });
    }
    if (Z['home:pasek']) o.STRIP = Z['home:pasek'].map(function (src) { return ofCms.nazwaPliku(src); });
    return o;
  }

  /* ------------------------------------------------------------------ */
  /* Back office : l'écran « strony » édite la page publique              */
  /* ------------------------------------------------------------------ */
  var STRONY = [['wspolne', 'wspólne · menu i stopka'], ['home', 'strona główna'], ['oferta', 'oferta'], ['omnie', 'o mnie'], ['portfolio', 'realizacje'], ['kontakt', 'kontakt']];
  var cmsIdx = {};          // sk de la maquette ("of:home:3") → élément
  var bo = { stan: null, init: null };

  function przygotujCms(js) {
    var C = dane.__cms;
    if (!C) return js;
    var html = pobierz(STRONA);
    var w = html && ofCms.wycinek(html);
    if (!w) return js;
    var tpl = html.slice(w[0], w[1]);
    var sjs = (/<script type="text\/x-dc" data-dc-script[^>]*>([\s\S]*?)<\/script>/.exec(html) || [])[1] || '';
    var items = ofCms.wyciagnij(tpl);
    var TABS = ofCms.wartoscPola(sjs, 'TABS') || [], CATS = ofCms.wartoscPola(sjs, 'CATS') || [], P = ofCms.wartoscPola(sjs, 'P') || [], STRIP = ofCms.wartoscPola(sjs, 'STRIP') || [];
    var f = function (src) { return String(src).indexOf('assets/photos/') === 0 ? src.slice(14) : '../../' + src; };
    // listes portées par le code de la page (menu, galerie, pasek)
    items.push({ k: 'wspolne:menu', page: 'wspolne', kind: 'lista', rola: 'Menu — nazwy zakładek (jedna na linię)', def: TABS.map(function (t) { return t.label; }).join('\n') });
    items.push({ k: 'home:pasek', page: 'home', kind: 'galeria', rola: 'Realizacje — pasek na stronie głównej', def: STRIP.map(function (n) { return 'assets/photos/ola/' + n + '.jpg'; }) });
    items.push({ k: 'portfolio:galeria', page: 'portfolio', kind: 'galeria', rola: 'Realizacje — galeria (kolejność jak na stronie)', def: P.map(function (p) { return p.src; }) });
    items.push({ k: 'portfolio:podpisy', page: 'portfolio', kind: 'lista', rola: 'Realizacje — podpisy i kategorie (plik | opis | kategoria)', def: P.map(function (p) { return ofCms.nazwaPliku(p.src) + ' | ' + p.a + ' | ' + p.c; }).join('\n') });
    items.push({ k: 'oferta:sezon', page: 'oferta', kind: 'lista', rola: 'Sezonowo w sklepie — nazwa | od (dd.mm) | do (dd.mm) | link do sklepu', def: ofCms.SEZON_DOMYSLNY });
    items.push({ k: 'portfolio:kategorie', page: 'portfolio', kind: 'lista', rola: 'Realizacje — nazwy filtrów (jedna na linię)', def: CATS.map(function (c) { return c.label; }).join('\n') });

    var PH_DEF = {}, stan = { cmsDraft: {}, cmsPub: {}, cmsStamp: {}, cmsPhotos: {} };
    var krotko = function (x) { return x.length > 38 ? x.slice(0, 37) + '…' : x; };
    var tytul = function (i) {
      if (i.kind === 'lista' || i.kind === 'galeria') return i.rola;
      if (i.tag === 'h1') return 'Tytuł strony';
      if (/^h[23]$/.test(i.tag)) return 'Nagłówek sekcji';
      var r = i.rola.charAt(0).toUpperCase() + i.rola.slice(1);
      if (i.strefa) return i.strefa + ' — ' + i.rola;
      return i.ctx ? r + ' · ' + krotko(i.ctx) : r + ' nad tytułem';
    };
    var pages = STRONY.map(function (st) {
      var secs = items.filter(function (i) { return i.page === st[0]; });
      var tytuly = {};
      return {
        id: st[0], label: st[1], tab: st[0] === 'wspolne' ? 'home' : st[0],
        sections: secs.map(function (i, n) {
          var foto = i.kind === 'zdjęcie' || i.kind === 'galeria';
          var t = tytul(i), b = t, k = 2;
          while (tytuly[t]) t = b + ' (' + (k++) + ')';
          tytuly[t] = 1;
          var sk = 'of:' + st[0] + ':' + n;
          cmsIdx[sk] = { k: i.k, page: i.page, kind: i.kind, title: t, def: i.def };
          var v = foto ? (i.kind === 'galeria' ? i.def.length + ' zdjęć' : (i.alt || ofCms.nazwaPliku(i.def))) : i.def;
          if (foto) {
            cmsIdx[sk].wyjsciowe = PH_DEF[t] = (i.kind === 'galeria' ? i.def : [i.def]).map(f);
            stan.cmsPhotos[sk] = cmsIdx[sk].wyjsciowe;       // la sélection de chaque section photo vient d'ici, jamais d'un titre partagé
            if (C.zdjecia && C.zdjecia[i.k]) stan.cmsPhotos[sk] = C.zdjecia[i.k].map(f);
            if (C.zdjeciaSzkic && C.zdjeciaSzkic[i.k]) stan.cmsPhotos[sk] = C.zdjeciaSzkic[i.k].map(f);
            if (C.stamp && C.stamp[i.k]) { stan.cmsPub[sk] = v; stan.cmsStamp[sk] = C.stamp[i.k]; }
          } else {
            if (C.pub && hasOwn(C.pub, i.k)) { stan.cmsPub[sk] = C.pub[i.k]; stan.cmsStamp[sk] = C.stamp[i.k] || ''; }
            if (C.szkic && hasOwn(C.szkic, i.k) && C.szkic[i.k] !== (hasOwn(stan.cmsPub, sk) ? stan.cmsPub[sk] : v)) stan.cmsDraft[sk] = C.szkic[i.k];
          }
          return { t: t, kind: i.kind, v: v };
        })
      };
    });
    dane.CMS = [{ id: 'of', label: 'ola flora · PL', dot: 'var(--of-linen-400)', file: STRONA + '?lang=pl', pages: pages }];
    // EN et DE : les mêmes textes et listes (photos et adresses sont communes), clé « en:… » / « de:… ».
    // La version de départ = la traduction du dictionnaire (du texte polonais publié, sinon d'origine).
    ['en', 'de'].forEach(function (L) {
      var sl = (C.slownik && C.slownik[L]) || {};
      var t1 = function (x, zapas) {
        var n = ofCms.norm(x);
        if (hasOwn(sl, n)) return sl[n];
        if (zapas != null && hasOwn(sl, ofCms.norm(zapas))) return sl[ofCms.norm(zapas)];
        return x;
      };
      var trLista = function (i, txt) {
        return String(txt).split(/\r?\n/).map(function (l) {
          var p = l.split('|');
          if (i.k === 'portfolio:podpisy' && p.length > 1) { p[1] = ' ' + t1(p[1].trim()) + ' '; return p.join('|'); }
          if (i.k === 'oferta:sezon' && p.length > 1) { p[0] = t1(p[0].trim()) + ' '; return p.join('|'); }
          return t1(l.trim());
        }).join('\n');
      };
      var pagesL = STRONY.map(function (st) {
        var tytulyL = {};
        var secs = items.filter(function (i) { return i.page === st[0] && (i.kind === 'tekst' || i.kind === 'lista'); });
        return {
          id: st[0], label: st[1], tab: st[0] === 'wspolne' ? 'home' : st[0],
          sections: secs.map(function (i, n) {
            var t = tytul(i), b = t, kk = 2;
            while (tytulyL[t]) t = b + ' (' + (kk++) + ')';
            tytulyL[t] = 1;
            var k = L + ':' + i.k, sk = 'of-' + L + ':' + st[0] + ':' + n;
            var plv = C.pub && hasOwn(C.pub, i.k) ? C.pub[i.k] : i.def;
            var v = i.kind === 'lista' ? trLista(i, plv) : t1(plv, i.def);
            cmsIdx[sk] = { k: k, page: i.page, kind: i.kind, title: t + ' · ' + L.toUpperCase(), def: v };
            if (C.pub && hasOwn(C.pub, k)) { stan.cmsPub[sk] = C.pub[k]; stan.cmsStamp[sk] = C.stamp[k] || ''; }
            if (C.szkic && hasOwn(C.szkic, k) && C.szkic[k] !== (hasOwn(stan.cmsPub, sk) ? stan.cmsPub[sk] : v)) stan.cmsDraft[sk] = C.szkic[k];
            return { t: t, kind: i.kind, v: v };
          })
        };
      });
      dane.CMS.push({ id: 'of-' + L, label: 'ola flora · ' + L.toUpperCase(), dot: 'var(--of-linen-400)', file: STRONA + '?lang=' + L, pages: pagesL });
    });
    dane.PH_DEF = PH_DEF;
    bo.init = stan;
    bo.bib = C.biblioteka || [];
    js = ofCms.owin(js, 'state', '__ofPoleBo');
    js = ofCms.owin(js, 'PHOTOS', '__ofPoleBo');
    return js;
  }
  window.__ofPoleBo = function (nazwa, v) {
    if (nazwa === 'state' && bo.init) {
      var s = Object.assign({}, v);
      ['cmsDraft', 'cmsPub', 'cmsStamp', 'cmsPhotos'].forEach(function (k) { s[k] = Object.assign({}, v[k] || {}, bo.init[k]); });
      bo.stan_prev = { cmsDraft: s.cmsDraft, cmsPub: s.cmsPub, cmsStamp: s.cmsStamp, cmsPhotos: s.cmsPhotos };
      return s;
    }
    if (nazwa === 'PHOTOS' && bo.bib) {
      var znane = {}; (v || []).forEach(function (p) { znane[p.f] = 1; });
      var plus = bo.bib.map(function (p) { return { f: String(p.src).indexOf('assets/photos/') === 0 ? p.src.slice(14) : '../../' + p.src, alt: p.alt || '' }; })
        .filter(function (p) { return !znane[p.f]; });
      return (v || []).concat(plus);
    }
    return v;
  };

  // Bouton « dodaj zdjęcie z dysku » sous la photothèque de chaque section photo du CMS.
  var WGRAJ = '<sc-if value="{{ sec.isPhoto }}" hint-placeholder-val="{{ false }}"><label style="align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: var(--fs-small); color: var(--brand-ink); border: 1px dashed var(--n-200); border-radius: 8px; padding: 8px 14px;"><input type="file" accept="image/jpeg,image/png,image/webp" data-of-wgraj="1" style="display: none;"><span>＋ dodaj zdjęcie z dysku</span></label></sc-if>';
  // L'entrée « Integracje i SEO » du rail du back office (écran à part, _wsb/prive/app/ustawienia.php).
  var USTAWIENIA_LINK = '<a data-of-ustawienia="1" href="_wsb/prive/app/ustawienia.php" style="display: flex; align-items: center; gap: var(--sp-2); text-decoration: none; font-size: var(--fs-small); color: var(--n-800); border: 1px solid var(--n-300); border-radius: 8px; padding: 8px 10px;">'
    + '<span style="flex: 0 0 auto; width: 14px; text-align: center; font-family: var(--font-mono); font-size: 11px;">◎</span>Integracje i SEO</a>'
    + '<a data-of-ustawienia="2" href="_wsb/prive/app/ustawienia.php?z=seo" style="display: flex; align-items: center; gap: var(--sp-2); text-decoration: none; font-size: var(--fs-small); color: var(--n-800); padding: 2px 10px;">'
    + '<span style="flex: 0 0 auto; width: 14px; text-align: center; font-family: var(--font-mono); font-size: 11px;">↗</span>Pozycjonowanie (SEO)</a>';
  function poprawBo(tpl) {
    if (tpl.indexOf('data-of-ustawienia') < 0) {
      var stopka = tpl.indexOf('<div style="margin-top: auto; display: flex; flex-direction: column; gap: var(--sp-2); border-top: 1px solid var(--n-200)');
      if (stopka >= 0) tpl = tpl.slice(0, stopka) + '<div style="display: flex; flex-direction: column; gap: 6px;">' + USTAWIENIA_LINK + '</div>' + tpl.slice(stopka);
    }
    if (tpl.indexOf('data-of-wgraj') >= 0) return tpl;
    var znak = '<sc-if value="{{ sec.isText }}" hint-placeholder-val="{{ true }}">';
    var i = tpl.indexOf(znak);
    return i < 0 ? tpl : tpl.slice(0, i) + WGRAJ + tpl.slice(i);
  }

  function zmniejsz(plik, ok) {
    var r = new FileReader();
    r.onload = function () {
      var im = new Image();
      im.onload = function () {
        var m = 2000, w = im.naturalWidth, h = im.naturalHeight, s = Math.min(1, m / Math.max(w, h));
        var c = document.createElement('canvas'); c.width = Math.round(w * s); c.height = Math.round(h * s);
        c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
        ok(c.toDataURL('image/jpeg', 0.86), c.width, c.height);
      };
      im.onerror = function () { alert('Nie udało się odczytać zdjęcia.'); };
      im.src = r.result;
    };
    r.readAsDataURL(plik);
  }
  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp || !inp.getAttribute || !inp.getAttribute('data-of-wgraj') || !inp.files || !inp.files[0]) return;
    var c = bo.stan, plik = inp.files[0];
    var sk = c && c.state && c.state.cmsEdit;
    inp.value = '';
    if (!c || !sk || !cmsIdx[sk]) return;
    zmniejsz(plik, function (dataUrl, w, h) {
      poster('prive/app/cms.php', { akcja: 'wgraj', nazwa: plik.name, data: dataUrl, w: w, h: h }, function (r) {
        if (!r || !r.src) { alert('Zdjęcie nie zostało zapisane' + (r && r.error ? ' (' + r.error + ')' : '') + '.'); return; }
        var f = '../../' + r.src;
        if (!c.PHOTOS.some(function (p) { return p.f === f; })) c.PHOTOS.push({ f: f, alt: r.alt || '' });
        c.setState(function (st) {
          var i = cmsIdx[sk], obecne = st.cmsPhotos[sk] || i.wyjsciowe || [];
          var nast = i.kind === 'zdjęcie' ? [f] : obecne.concat([f]);
          var o = Object.assign({}, st.cmsPhotos); o[sk] = nast;
          return { cmsPhotos: o };
        });
      });
    });
  }, true);

  var szkicCzas = {};
  function cmsGeste(c) {
    var s = c.state || {}, p = bo.stan_prev || { cmsDraft: {}, cmsPub: {}, cmsStamp: {}, cmsPhotos: {} };
    bo.stan = c;
    var ciało = function (sk, extra) {
      var i = cmsIdx[sk];
      return Object.assign({ k: i.k, strona: i.page, rodzaj: i.kind, tytul: i.title, domyslna: Array.isArray(i.def) ? i.def : String(i.def) }, extra);
    };
    var zdjecia = function (sk) {
      var i = cmsIdx[sk];
      var sel = s.cmsPhotos[sk] || i.wyjsciowe || [];
      return sel.map(srcZ);
    };
    // 1. une seule photo : le dernier clic remplace la précédente
    Object.keys(s.cmsPhotos || {}).forEach(function (sk) {
      var i = cmsIdx[sk];
      if (i && i.kind === 'zdjęcie' && s.cmsPhotos[sk].length > 1 && s.cmsPhotos[sk] !== p.cmsPhotos[sk]) {
        var dernier = s.cmsPhotos[sk].filter(function (x) { return (p.cmsPhotos[sk] || i.wyjsciowe || []).indexOf(x) < 0; }).pop() || s.cmsPhotos[sk][s.cmsPhotos[sk].length - 1];
        setTimeout(function () { c.setState(function (st) { var o = Object.assign({}, st.cmsPhotos); o[sk] = [dernier]; return { cmsPhotos: o }; }); }, 0);
      }
    });
    // 2. brouillons (texte et photos), enregistrés après une pause de frappe
    Object.keys(cmsIdx).forEach(function (sk) {
      var i = cmsIdx[sk];
      var foto = i.kind === 'zdjęcie' || i.kind === 'galeria';
      var zmienione = foto ? s.cmsPhotos[sk] !== p.cmsPhotos[sk] && s.cmsPhotos[sk] : s.cmsDraft[sk] !== p.cmsDraft[sk] && hasOwn(s.cmsDraft, sk);
      if (!zmienione) return;
      if (foto && i.kind === 'zdjęcie' && s.cmsPhotos[sk].length > 1) return;
      clearTimeout(szkicCzas[sk]);
      szkicCzas[sk] = setTimeout(function () {
        var st = bo.stan.state;
        poster('prive/app/cms.php', ciało(sk, foto ? { akcja: 'szkic', zdjecia: (st.cmsPhotos[sk] || []).map(srcZ) } : { akcja: 'szkic', wartosc: st.cmsDraft[sk] }));
      }, 700);
    });
    // 3. publier / revenir à la version d'origine
    var stampZmiana = s.cmsPub !== p.cmsPub;   // « Publikuj » refait toujours cmsPub, même pour un texte inchangé
    var opub = [];
    Object.keys(s.cmsPub || {}).forEach(function (sk) {
      if (!cmsIdx[sk]) return;
      if (!hasOwn(p.cmsPub, sk) || s.cmsPub[sk] !== p.cmsPub[sk] || s.cmsStamp[sk] !== p.cmsStamp[sk]) opub.push(sk);
    });
    if (stampZmiana && !opub.length && s.cmsEdit && hasOwn(s.cmsPub, s.cmsEdit) && cmsIdx[s.cmsEdit]) opub.push(s.cmsEdit);
    opub.forEach(function (sk) {
      var i = cmsIdx[sk], foto = i.kind === 'zdjęcie' || i.kind === 'galeria';
      clearTimeout(szkicCzas[sk]);
      poster('prive/app/cms.php', ciało(sk, foto ? { akcja: 'publikuj', zdjecia: zdjecia(sk) } : { akcja: 'publikuj', wartosc: s.cmsPub[sk] }), function (r, kod) {
        if (kod !== 200 || !r.ok) alert('Nie opublikowano: ' + ((r && r.error) || 'błąd serwera') + '.');
      });
    });
    Object.keys(p.cmsPub || {}).forEach(function (sk) {
      if (!cmsIdx[sk] || hasOwn(s.cmsPub, sk)) return;
      clearTimeout(szkicCzas[sk]);
      poster('prive/app/cms.php', ciało(sk, { akcja: 'przywroc' }));
      var i = cmsIdx[sk];
      if ((i.kind === 'zdjęcie' || i.kind === 'galeria') && s.cmsPhotos[sk]) {
        setTimeout(function () { c.setState(function (st) { var o = Object.assign({}, st.cmsPhotos); o[sk] = i.wyjsciowe; return { cmsPhotos: o }; }); }, 0);
      }
    });
    bo.stan_prev = { cmsDraft: s.cmsDraft || {}, cmsPub: s.cmsPub || {}, cmsStamp: s.cmsStamp || {}, cmsPhotos: s.cmsPhotos || {} };
  }

  // Les gestes à enregistrer, par écran.
  function geste(c, nazwa) {
    var s = c.state || {};
    // Le formulaire : seul (FormularzWizard.dc.html) ou embarqué dans l'onglet « formularz » de la page.
    if (nazwa === 'FormularzWizard' && s.done && !c.__ofWyslane) {
      c.__ofWyslane = true;
      poster('app/zapytanie.php', { vals: s.vals || {}, multi: s.multi || [], palette: s.palette || '', accents: s.accents || [], lang: jezyk },
        function (r) { if (r && r.ok && window.__ofKonwersja) window.__ofKonwersja(); });
    }
    if (nazwa === 'FormularzWizard' && !s.done && c.__ofWyslane) c.__ofWyslane = false;   // « od nowa »
    if (nazwa === 'Back office' && dane.__cms) cmsGeste(c);
  }

  /* ------------------------------------------------------------------ */
  /* Google (Analytics 4, Ads) — RIEN ne se charge sans l'accord du       */
  /* visiteur. Les identifiants viennent de seo.php (window.__ofGoogle),  */
  /* réglés dans le back office → Integracje i SEO. Refus = aucun cookie. */
  /* ------------------------------------------------------------------ */
  (function google() {
    var g = window.__ofGoogle || {};
    if (prive || (!g.ga && !g.ads)) return;
    var zgoda = (/(?:^|; )olaflora_zgoda=(tak|nie)/.exec(document.cookie) || [])[1];
    function laduj() {
      if (window.__ofGtag) return;
      window.__ofGtag = true;
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      if (g.ga) window.gtag('config', g.ga, { anonymize_ip: true });
      if (g.ads) window.gtag('config', g.ads);
      var sc = document.createElement('script'); sc.async = true;
      sc.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(g.ga || g.ads);
      document.head.appendChild(sc);
    }
    window.__ofKonwersja = function () {
      if (!window.gtag) return;
      if (g.ga) window.gtag('event', 'generate_lead', { lang: jezyk });
      if (g.ads && g.adsLabel) window.gtag('event', 'conversion', { send_to: g.ads + '/' + g.adsLabel });
    };
    if (zgoda === 'tak') { laduj(); return; }
    if (zgoda === 'nie') return;
    var T = { pl: ['Ta strona używa plików cookies Google do mierzenia ruchu i skuteczności reklam.', 'Akceptuję', 'Odrzucam'],
              en: ['This site uses Google cookies to measure traffic and ad performance.', 'Accept', 'Decline'],
              de: ['Diese Seite verwendet Google-Cookies, um Besuche und Werbewirkung zu messen.', 'Akzeptieren', 'Ablehnen'] }[jezyk] || [];
    function pasek() {
      if (document.getElementById('of-zgoda')) return;
      var d = document.createElement('div'); d.id = 'of-zgoda'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-live', 'polite');
      d.setAttribute('style', 'position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:560px;margin:0 auto;background:#fbf8f3;color:#2e2924;border:1px solid #d8cdbd;padding:16px 18px;font:400 14px/1.5 "Work Sans",system-ui,sans-serif;display:flex;flex-wrap:wrap;gap:12px;align-items:center;box-shadow:0 8px 28px rgba(46,41,36,.12)');
      var btn = 'font:inherit;font-size:12px;letter-spacing:.16em;text-transform:uppercase;padding:9px 16px;cursor:pointer;border:1px solid #2e2924;';
      d.innerHTML = '<span style="flex:1 1 260px">' + T[0] + '</span><button type="button" data-z="nie" style="' + btn + 'background:transparent;color:#2e2924">' + T[2] + '</button><button type="button" data-z="tak" style="' + btn + 'background:#2e2924;color:#f4efe7">' + T[1] + '</button>';
      d.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-z]'); if (!b) return;
        var v = b.getAttribute('data-z');
        document.cookie = 'olaflora_zgoda=' + v + '; path=/; max-age=' + (v === 'tak' ? 31536000 : 15552000) + '; SameSite=Lax';
        d.remove(); if (v === 'tak') laduj();
      });
      document.body.appendChild(d);
    }
    if (document.body) pasek(); else document.addEventListener('DOMContentLoaded', pasek);
  })();

  window.__ofKlasa = function (Base, nazwa) {
    return class extends Base {
      setState(u, cb) {
        var self = this;
        return super.setState(u, function () { if (cb) cb(); try { geste(self, nazwa || ekran); } catch (e) { if (window.console) console.error(e); } });
      }
    };
  };

  // Le sélecteur PL · EN · DE : garde l'onglet affiché (#oferta…).
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-of-lang]') : null;
    if (!a) return;
    e.preventDefault();
    var l = a.getAttribute('data-of-lang');
    try { document.cookie = 'olaflora_lang=' + l + '; path=/; max-age=31536000; SameSite=Lax'; } catch (er) {}
    location.href = location.pathname + '?lang=' + l + location.hash;
  });

  document.addEventListener('DOMContentLoaded', function () {
    var el = document.querySelector('script[data-dc-script]');
    var dc = document.querySelector('x-dc');
    if (!el) return;
    var js = el.textContent;
    if (publiczna) {
      cmsPub = cmsPub || {};
      var l = listyPubliczne(js, cmsPub);
      Object.keys(l).forEach(function (k) { dane[k] = l[k]; });
      if (dc) { var stary = dc.innerHTML, nowy = ofCms.zastosuj(stary, cmsPub); if (nowy !== stary) dc.innerHTML = nowy; }
      przechwycFetch(function (tpl) { return ofCms.zastosuj(tpl, cmsPub); }, ['FormularzWizard']);
    }
    if (formularz) {
      js = ofCms.tlumaczJs(js);
      if (dc && jezyk !== 'pl') dc.innerHTML = ofCms.tlumaczSzablon(dc.innerHTML);
      przechwycFetch(jezyk !== 'pl' ? ofCms.tlumaczSzablon : null, null);
    }
    if (ekran === 'Back office') {
      // Base sans aucune demande : l'écran « zapytania » lisait this.ZAP[0].id et tout le back office tombait.
      js = js.replace(/(\|\|\s*this\.ZAP\[0\]);/, '$1 || window.__ofPustyZap;');
    }
    if (ekran === 'Back office' && dane.__cms) {
      js = przygotujCms(js);
      if (dc) dc.innerHTML = poprawBo(dc.innerHTML);
      przechwycFetch(poprawBo);
    }
    js = js.replace(/class\s+Component\s+extends\s+DCLogic\b/, 'class Component extends window.__ofKlasa(DCLogic, ' + JSON.stringify(ekran) + ')');
    Object.keys(dane).forEach(function (k) {
      if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(k)) return;
      js = js.replace(new RegExp('^(\\s{2})' + k + '\\s*=\\s*', 'm'),
        '$1' + k + ' = Object.prototype.hasOwnProperty.call(window.__of_dane, "' + k + '") ? window.__of_dane["' + k + '"] : ');
    });
    el.textContent = js;
  });
})();
