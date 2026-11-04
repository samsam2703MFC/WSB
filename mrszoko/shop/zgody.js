/*
 * zgody.js — l'accord du visiteur, puis (seulement alors) Google et Meta.
 *
 * La page ne contient AUCUN script tiers. Celui-ci lit la config posée par le
 * serveur (#wsm-tags : identifiants publics + événements de la page), ouvre la
 * bannière tant qu'aucun choix n'est fait, et ne charge gtag.js (GA4, Google
 * Ads) ou le pixel Meta qu'une fois la catégorie acceptée :
 *   · analityczne  → Google Analytics 4
 *   · marketingowe → Google Ads (conversion « zakup ») et pixel Meta
 *
 * Le choix vit dans le cookie wsm_zgody (« v1-a1-m0 »), un an. Le serveur le
 * relit à la caisse : sans « marketingowe », aucun achat ne part chez Meta
 * par la Conversions API non plus.
 *
 * Retirer un accord efface les cookies de mesure et recharge la page : un
 * script déjà chargé ne se décharge pas, et continuer à mesurer quelqu'un qui
 * vient de dire non serait pire que de recharger.
 */
(function () {
  'use strict';
  var box = document.getElementById('wsm-tags');
  if (!box) return;
  var cfg;
  try { cfg = JSON.parse(box.textContent || '{}'); } catch (e) { return; }
  var NAME = cfg.cookie || 'wsm_zgody';
  var PLN = 'PLN';

  function read() {
    var m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=v1-a([01])-m([01])(?:;|$)'));
    return m ? { a: m[1] === '1', m: m[2] === '1' } : null;
  }
  function write(z) {
    var v = 'v1-a' + (z.a ? 1 : 0) + '-m' + (z.m ? 1 : 0);
    document.cookie = NAME + '=' + v + '; Max-Age=' + (86400 * (cfg.days || 365)) + '; Path=/; SameSite=Lax'
      + (location.protocol === 'https:' ? '; Secure' : '');
  }
  function forget() {
    // Le cookie peut avoir été posé sur n'importe quel domaine parent
    // (.szoko.com.pl, pas seulement .com.pl) : on les essaie tous.
    var parts = location.hostname.split('.'), domains = [''];
    for (var i = 0; i < parts.length - 1; i++) domains.push('.' + parts.slice(i).join('.'));
    document.cookie.split('; ').forEach(function (c) {
      var n = c.split('=')[0];
      if (!/^(_ga|_gid|_gat|_gcl_|_fbp|_fbc)/.test(n)) return;
      domains.forEach(function (d) {
        document.cookie = n + '=; Max-Age=0; Path=/' + (d ? '; Domain=' + d : '');
      });
    });
  }

  // ---------------------------------------------------------------- chargement
  var loaded = { g: false, p: false, ga4: false, ads: false };
  function gtag() { window.dataLayer.push(arguments); }

  function loadGoogle(z) {
    var ga = z.a && cfg.ga4, ads = z.m && cfg.ads;
    if (loaded.g) {
      // Déjà chargé, et l'accord vient de s'élargir (analytique puis
      // marketing, par exemple) : on le DIT à gtag et on configure ce qui
      // manque, sans recharger le script.
      gtag('consent', 'update', {
        analytics_storage: z.a ? 'granted' : 'denied',
        ad_storage: z.m ? 'granted' : 'denied',
        ad_user_data: z.m ? 'granted' : 'denied',
        ad_personalization: z.m ? 'granted' : 'denied'
      });
      if (ga && !loaded.ga4) { gtag('config', cfg.ga4); loaded.ga4 = true; }
      if (ads && !loaded.ads) { gtag('config', cfg.ads); loaded.ads = true; }
      return;
    }
    if (!ga && !ads) return;
    loaded.g = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = gtag;
    gtag('consent', 'default', {
      analytics_storage: z.a ? 'granted' : 'denied',
      ad_storage: z.m ? 'granted' : 'denied',
      ad_user_data: z.m ? 'granted' : 'denied',
      ad_personalization: z.m ? 'granted' : 'denied'
    });
    gtag('js', new Date());
    if (ga) { gtag('config', cfg.ga4); loaded.ga4 = true; }
    if (ads) { gtag('config', cfg.ads); loaded.ads = true; }
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga ? cfg.ga4 : cfg.ads);
    document.head.appendChild(s);
  }

  function loadPixel(z) {
    if (loaded.p || !z.m || !cfg.pixel) return;
    loaded.p = true;
    /* Le chargeur officiel du pixel, réécrit lisiblement. */
    var f = window.fbq = function () {
      f.callMethod ? f.callMethod.apply(f, arguments) : f.queue.push(arguments);
    };
    if (!window._fbq) window._fbq = f;
    f.push = f; f.loaded = true; f.version = '2.0'; f.queue = [];
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://connect.facebook.net/en_US/fbevents.js';
    document.head.appendChild(s);
    window.fbq('init', cfg.pixel);
    window.fbq('track', 'PageView');
  }

  // ---------------------------------------------------------------- événements
  function gaItems(items) {
    return (items || []).map(function (i) {
      var o = { item_id: i.id, item_name: i.name || '', price: i.price || 0, quantity: i.qty || 1 };
      if (i.cat) o.item_category = i.cat;
      return o;
    });
  }
  function ids(items) { return (items || []).map(function (i) { return i.id; }); }

  function send(ev, z) {
    var g = loaded.g && window.gtag, p = loaded.p && window.fbq;
    var items = ev.items || [], value = ev.value || 0;
    if (ev.n === 'view_item') {
      if (g && z.a) gtag('event', 'view_item', { currency: PLN, value: value, items: gaItems(items) });
      if (p) window.fbq('track', 'ViewContent', { content_ids: ids(items), content_type: 'product', value: value, currency: PLN });
    } else if (ev.n === 'add_to_cart') {
      if (g && z.a) gtag('event', 'add_to_cart', { currency: PLN, value: value, items: gaItems(items) });
      if (p) window.fbq('track', 'AddToCart', { content_ids: ids(items), content_type: 'product', value: value, currency: PLN });
    } else if (ev.n === 'begin_checkout') {
      if (g && z.a) gtag('event', 'begin_checkout', { currency: PLN, value: value, items: gaItems(items) });
      if (p) window.fbq('track', 'InitiateCheckout', { content_ids: ids(items), content_type: 'product', value: value, currency: PLN, num_items: items.length });
    } else if (ev.n === 'purchase') {
      // UNE FOIS PAR COMMANDE. La page de commande se recharge (retour de
      // paiement, lien du mail) ; compter l'achat à chaque fois gonflerait le
      // chiffre d'affaires mesuré. Google et Meta dédoublonnent aussi
      // (transaction_id, eventID), mais on n'envoie pas ce qu'on sait déjà parti.
      var key = 'wsm_zak_' + ev.id;
      try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) { /* stockage bloqué */ }
      if (g && z.a) gtag('event', 'purchase', { transaction_id: ev.tx, currency: PLN, value: value, shipping: ev.shipping || 0, items: gaItems(items) });
      if (g && z.m && cfg.ads && cfg.adsLabel) gtag('event', 'conversion', { send_to: cfg.ads + '/' + cfg.adsLabel, value: value, currency: PLN, transaction_id: ev.tx });
      if (p) window.fbq('track', 'Purchase', {
        content_ids: ids(items), content_type: 'product', value: value, currency: PLN, num_items: items.length,
        contents: items.map(function (i) { return { id: i.id, quantity: i.qty || 1, item_price: i.price || 0 }; })
      }, { eventID: ev.id });
    }
  }

  // L'accord en vigueur, relu par l'écouteur du panier à chaque ajout.
  var cur = null, started = false;
  function start(z) {
    cur = z;
    loadGoogle(z);
    loadPixel(z);
    // UNE FOIS PAR PAGE : les événements de la page et l'écouteur du panier.
    // Rouvrir la bannière et enregistrer à nouveau ne doit ni recompter la
    // vue du produit, ni doubler chaque ajout au panier.
    if (started) return;
    started = true;
    (cfg.events || []).forEach(function (ev) { send(ev, z); });
    document.addEventListener('wsm:dodano', function (e) {
      var d = e.detail || {};
      if (!d.id || !cur) return;
      send({ n: 'add_to_cart', value: (d.price || 0) * (d.qty || 1), items: [{ id: d.id, price: d.price || 0, qty: d.qty || 1 }] }, cur);
    });
  }

  // ---------------------------------------------------------------- bannière
  var ban = document.getElementById('zgody');
  function q(sel) { return ban ? ban.querySelector(sel) : null; }
  function show(details) {
    if (!ban) return;
    ban.hidden = false;
    var cur = read();
    var opts = q('.zgody-opcje');
    if (details && opts) {
      opts.hidden = false;
      var a = q('input[name="a"]'), m = q('input[name="m"]');
      if (a) a.checked = !!(cur && cur.a);
      if (m) m.checked = !!(cur && cur.m);
      var s = q('[data-zgody="save"]'), more = q('[data-zgody="more"]');
      if (s) s.hidden = false;
      if (more) more.hidden = true;
    }
    // Le focus ne bouge que si le visiteur a lui-même rouvert la bannière :
    // à l'arrivée sur la page, on ne lui vole pas son point de lecture.
    var first = q('button');
    if (details && first) first.focus({ preventScroll: true });
  }
  function choose(z) {
    var before = read();
    write(z);
    if (ban) ban.hidden = true;
    if (before && ((before.a && !z.a) || (before.m && !z.m))) { forget(); location.reload(); return; }
    start(z);
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-zgody]') : null;
    if (!b) return;
    var act = b.getAttribute('data-zgody');
    if (act === 'open') { e.preventDefault(); show(true); return; }
    if (act === 'all') choose({ a: true, m: true });
    else if (act === 'none') choose({ a: false, m: false });
    else if (act === 'more') show(true);
    else if (act === 'save') {
      var a = q('input[name="a"]'), m = q('input[name="m"]');
      choose({ a: !!(a && a.checked), m: !!(m && m.checked) });
    }
  });

  var z = read();
  if (z) start(z); else show(false);
})();
