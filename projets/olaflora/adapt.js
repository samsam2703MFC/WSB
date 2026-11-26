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

  var dane = prive ? json(pobierz(base + 'prive/app/dane.php?ekran=' + encodeURIComponent(ekran))) : {};
  var cmsPub = publiczna ? json(pobierz(base + 'app/strona.php')) : null;   // { t: {k: tekst}, z: {k: [src]}, alt: {src: alt} }
  window.__of_dane = dane;

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

    function wyciagnij(html) { var out = []; przejdz(szablon(html), function (i) { out.push(i); }); return out; }

    // Remplace dans le gabarit ce qui a été publié. pub = { t: {k: tekst}, z: {k: [src]}, alt: {src: alt} }
    function zastosuj(html, pub) {
      if (!pub || (!pub.t && !pub.z)) return html;
      var t = szablon(html), T = pub.t || {}, Z = pub.z || {}, A = pub.alt || {}, zmiana = false;
      przejdz(t, function (i, n) {
        if (i.kind === 'tekst' && hasOwn(T, i.k) && String(T[i.k]).trim() !== '') {
          var m = /^(\s*)[\s\S]*?(\s*)$/.exec(n.nodeValue);
          n.nodeValue = m[1] + T[i.k] + m[2]; zmiana = true;
        } else if (i.kind === 'zdjęcie' && hasOwn(Z, i.k) && Z[i.k] && Z[i.k][0]) {
          var src = Z[i.k][0];
          n.setAttribute('src', src);
          if (A[src]) n.setAttribute('alt', A[src]);
          n.removeAttribute('ref');          // le héros était forcé par le réglage « hero » : la photo publiée l'emporte
          zmiana = true;
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
    return { wyciagnij: wyciagnij, zastosuj: zastosuj, pole: pole, wartoscPola: wartoscPola, owin: owin, wycinek: wycinek, nazwaPliku: nazwaPliku, norm: norm };
  })();
  window.__ofCms = ofCms;
  window.__ofPustyZap = { id: '', iso: '', who: '', when: '', venue: '', ceremony: '', guests: '', budget: '', style: '', palette: '',
    tables: '', head: '', extras: '', mail: '', tel: '', ago: '', state: '', day: [] };

  // Le runtime relit la page brute (fetch(location.href)) pour son gabarit : on lui rend la même version retouchée.
  function przechwycFetch(popraw) {
    var orig = window.fetch;
    if (!orig) return;
    var ici = location.href.split('#')[0];
    window.fetch = function (u, o) {
      var adr = typeof u === 'string' ? u : (u && u.url) || '';
      var abs = ''; try { abs = new URL(adr, location.href).href.split('#')[0]; } catch (e) {}
      if (abs !== ici) return orig.apply(this, arguments);
      return orig.apply(this, arguments).then(function (res) {
        if (!res.ok) return res;
        return res.text().then(function (t) {
          var w = ofCms.wycinek(t);
          if (w) t = t.slice(0, w[0]) + popraw(t.slice(w[0], w[1])) + t.slice(w[1]);
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
    var T = (pub && pub.t) || {}, Z = (pub && pub.z) || {}, A = (pub && pub.alt) || {}, K = (pub && pub.kat) || {}, o = {};
    var TABS = ofCms.wartoscPola(js, 'TABS'), CATS = ofCms.wartoscPola(js, 'CATS'), P = ofCms.wartoscPola(js, 'P');
    if (TABS && T['wspolne:menu']) {
      var l = linie(T['wspolne:menu']);
      o.TABS = TABS.map(function (t, i) { return { id: t.id, label: l[i] || t.label }; });
    }
    if (CATS && T['portfolio:kategorie']) {
      var lk = linie(T['portfolio:kategorie']);
      o.CATS = CATS.map(function (c, i) { return { id: c.id, label: lk[i] || c.label }; });
    }
    if (P && (Z['portfolio:galeria'] || T['portfolio:podpisy'])) {
      var opis = {};
      linie(T['portfolio:podpisy']).forEach(function (s) {
        var p = s.split('|').map(function (x) { return x.trim(); });
        if (p[0]) opis[p[0]] = { a: p[1] || '', c: p[2] || '' };
      });
      var przezNazwe = {}; P.forEach(function (p) { przezNazwe[ofCms.nazwaPliku(p.src)] = p; });
      var lista = Z['portfolio:galeria'] ? Z['portfolio:galeria'] : P.map(function (p) { return p.src; });
      o.P = lista.map(function (src) {
        var nz = ofCms.nazwaPliku(src), d = przezNazwe[nz] || {}, op = opis[nz] || {};
        return { src: src, a: op.a || d.a || A[src] || nz.replace(/-/g, ' '), c: op.c || d.c || K[src] || '', sc: d.sc || 1 };
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
    dane.CMS = [{ id: 'of', label: 'ola flora', dot: 'var(--of-linen-400)', file: STRONA, pages: pages }];
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
  function poprawBo(tpl) {
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
  function geste(c) {
    var s = c.state || {};
    if (ekran === 'FormularzWizard' && s.done && !c.__ofWyslane) {
      c.__ofWyslane = true;
      poster('app/zapytanie.php', { vals: s.vals || {}, multi: s.multi || [], palette: s.palette || '', accents: s.accents || [] });
    }
    if (!s.done && c.__ofWyslane && ekran === 'FormularzWizard') c.__ofWyslane = false;   // « od nowa »
    if (ekran === 'Back office' && dane.__cms) cmsGeste(c);
  }

  window.__ofKlasa = function (Base) {
    return class extends Base {
      setState(u, cb) {
        var self = this;
        return super.setState(u, function () { if (cb) cb(); try { geste(self); } catch (e) { if (window.console) console.error(e); } });
      }
    };
  };

  document.addEventListener('DOMContentLoaded', function () {
    var el = document.querySelector('script[data-dc-script]');
    var dc = document.querySelector('x-dc');
    if (!el) return;
    var js = el.textContent;
    if (publiczna && cmsPub) {
      var l = listyPubliczne(js, cmsPub);
      Object.keys(l).forEach(function (k) { dane[k] = l[k]; });
      if (dc) { var stary = dc.innerHTML, nowy = ofCms.zastosuj(stary, cmsPub); if (nowy !== stary) dc.innerHTML = nowy; }
      przechwycFetch(function (tpl) { return ofCms.zastosuj(tpl, cmsPub); });
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
    js = js.replace(/class\s+Component\s+extends\s+DCLogic\b/, 'class Component extends window.__ofKlasa(DCLogic)');
    Object.keys(dane).forEach(function (k) {
      if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(k)) return;
      js = js.replace(new RegExp('^(\\s{2})' + k + '\\s*=\\s*', 'm'),
        '$1' + k + ' = Object.prototype.hasOwnProperty.call(window.__of_dane, "' + k + '") ? window.__of_dane["' + k + '"] : ');
    });
    el.textContent = js;
  });
})();
