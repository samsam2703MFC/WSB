/* =====================================================================
   Mister Szoko — landing, rendu data-driven multilingue (pl / uk / en).
   =====================================================================
   Source de vérité au runtime : l'API publique
     GET ../backoffice/api/landing/content?lang=xx
   (tables wsm_landing_i18n + wsm_landing_products, éditables via l'API
   admin sans redéploiement). Repli hors-API (GitHub Pages, dev, panne) :
   content_seed.json — le MÊME fichier qui seed la base côté serveur.
   La page ne contient AUCUN texte en dur ; app.js non plus.
   ===================================================================== */
(function () {
  'use strict';

  var API = '../backoffice/api/landing/content'; // same-origin, relatif au dossier de la page
  var SEED = 'content_seed.json';
  var STORE = 'ms_lang';
  // Libellés des pilules du sélecteur (représentation des codes ISO, pas du contenu).
  var CODE_LABEL = { pl: 'PL', uk: 'UA', en: 'EN' };

  function pickLang(available, fallback) {
    try {
      var q = new URLSearchParams(location.search).get('lang');
      if (q && available.indexOf(q) !== -1) return q;
      var st = localStorage.getItem(STORE);
      if (st && available.indexOf(st) !== -1) return st;
      var navs = navigator.languages || [navigator.language || ''];
      for (var i = 0; i < navs.length; i++) {
        var c = String(navs[i]).slice(0, 2).toLowerCase();
        if (available.indexOf(c) !== -1) return c;
      }
    } catch (e) {}
    return fallback;
  }

  function fetchJSON(url) {
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error(url + ' -> ' + r.status);
      return r.json();
    });
  }

  // Repli : reconstruit localement la même forme que la réponse API.
  function fromSeed(doc, lang) {
    var langs = doc.langs || Object.keys(doc.strings || {});
    var def = doc.default || langs[0];
    if (langs.indexOf(lang) === -1) lang = def;
    var strings = (doc.strings || {})[lang] || {};
    var products = (doc.products || []).filter(function (p) { return p.active !== 0; })
      .sort(function (a, b) { return (a.sort_order || 0) - (b.sort_order || 0); })
      .map(function (p) {
        return {
          id: p.id, fluidity: p.fluidity, swatch_from: p.swatch_from, swatch_to: p.swatch_to,
          price_from: { pln: p.price_from_pln, eur: p.price_from_eur },
          price_perkg: { pln: p.price_perkg_pln, eur: p.price_perkg_eur },
          name: strings['product.' + p.id + '.name'] || p.id,
          meta: strings['product.' + p.id + '.meta'] || '',
          specs: strings['product.' + p.id + '.specs'] || ''
        };
      });
    return { lang: lang, default: def, langs: langs, strings: strings, products: products };
  }

  function loadContent(lang) {
    var url = API + (lang ? '?lang=' + encodeURIComponent(lang) : '');
    return fetchJSON(url).catch(function () {
      return fetchJSON(SEED).then(function (doc) { return fromSeed(doc, lang); });
    });
  }

  // ---- Rendu ----------------------------------------------------------------
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function money(value, currency, locale) {
    if (value == null) return '';
    try {
      return new Intl.NumberFormat(locale, { style: 'currency', currency: currency }).format(value);
    } catch (e) { return value + ' ' + currency; }
  }

  function renderDots(n, title) {
    var wrap = el('span', 'dots');
    wrap.title = title;
    for (var i = 1; i <= 5; i++) {
      var d = el('i');
      if (i <= n) d.className = 'on';
      wrap.appendChild(d);
    }
    return wrap;
  }

  function renderProducts(data) {
    var S = data.strings;
    var cur = (S.currency || 'PLN').toLowerCase();
    var grid = document.getElementById('grid-products');
    grid.textContent = '';
    data.products.forEach(function (p) {
      var card = el('article', 'pcard');

      var photo = el('div', 'pcard-photo');
      photo.style.background = 'linear-gradient(135deg, var(' + p.swatch_from + '), var(' + p.swatch_to + '))';
      photo.appendChild(el('span', null, S['placeholder.photo'] || ''));
      card.appendChild(photo);

      var body = el('div', 'pcard-body');
      var meta = el('div', 'pcard-meta');
      meta.appendChild(el('span', null, p.meta));
      meta.appendChild(renderDots(p.fluidity, (S['fluidity.title'] || '{n}/5').replace('{n}', p.fluidity)));
      body.appendChild(meta);
      body.appendChild(el('h3', null, p.name));
      body.appendChild(el('div', 'specs', p.specs));

      var price = el('div', 'price');
      var left = el('span');
      left.appendChild(document.createTextNode((S['price.from'] || '') + ' ' + money(p.price_from[cur], S.currency, S.locale) + ' '));
      left.appendChild(el('span', 'ht', S['price.net'] || ''));
      price.appendChild(left);
      price.appendChild(el('span', 'perkg', money(p.price_perkg[cur], S.currency, S.locale) + (S['price.perkg'] || '')));
      body.appendChild(price);

      card.appendChild(body);
      grid.appendChild(card);
    });
  }

  function renderFormats(S) {
    var grid = document.getElementById('grid-formats');
    grid.textContent = '';
    for (var i = 1; S['format.' + i + '.size']; i++) {
      var f = el('div', 'format');
      f.appendChild(el('div', 'fk', S['format.' + i + '.kind'] || ''));
      f.appendChild(el('div', 'fs', S['format.' + i + '.size']));
      f.appendChild(el('p', null, S['format.' + i + '.note'] || ''));
      grid.appendChild(f);
    }
  }

  function renderSwitcher(data) {
    var wrap = document.getElementById('lang-switch');
    wrap.setAttribute('aria-label', data.strings['a11y.lang'] || '');
    wrap.textContent = '';
    data.langs.forEach(function (code) {
      var b = el('button', null, CODE_LABEL[code] || code.toUpperCase());
      b.type = 'button';
      b.lang = code;
      b.setAttribute('aria-current', String(code === data.lang));
      b.addEventListener('click', function () { switchLang(code); });
      wrap.appendChild(b);
    });
  }

  function render(data) {
    var S = data.strings;
    document.documentElement.lang = data.lang;
    document.title = S['meta.title'] || 'Mister Szoko';
    var md = document.querySelector('meta[name="description"]');
    if (md) md.setAttribute('content', S['meta.desc'] || '');

    document.querySelectorAll('[data-i18n]').forEach(function (node) {
      var v = S[node.getAttribute('data-i18n')];
      if (v != null) node.textContent = v;
    });

    document.getElementById('home-link').setAttribute('aria-label', S['a11y.home'] || '');
    document.getElementById('main-nav').setAttribute('aria-label', S['a11y.nav'] || '');

    var email = S['footer.email'] || '';
    document.getElementById('footer-email').href = 'mailto:' + email;
    document.getElementById('pro-cta').href =
      'mailto:' + email + '?subject=' + encodeURIComponent(S['pro.mail_subject'] || '');

    renderProducts(data);
    renderFormats(S);
    renderSwitcher(data);
  }

  function switchLang(code) {
    try { localStorage.setItem(STORE, code); } catch (e) {}
    try {
      var q = new URLSearchParams(location.search);
      q.set('lang', code);
      history.replaceState({}, '', location.pathname + '?' + q.toString() + location.hash);
    } catch (e) {}
    loadContent(code).then(render);
  }

  loadContent(pickLang(['pl', 'uk', 'en'], 'pl')).then(render).catch(function (e) {
    console.error('Mister Szoko landing: contenu indisponible', e);
  });
})();
