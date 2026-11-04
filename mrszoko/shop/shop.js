/* ============================================================================
   Mister Szoko — boutique : confort, pas fondation.
   La page est déjà complète et cliquable quand ce fichier arrive (il est en
   `defer`). Tout ce qu'il fait, un formulaire POST le fait déjà sans lui :
     · ajouter au panier sans recharger la page ;
     · afficher les bons champs selon le mode de livraison et la facture ;
     · recalculer le panier sur place (« − », « + », corbeille, livraison) ;
     · suivre la quantité dans la boîte des paliers de la fiche produit ;
     · faire tourner les slajdy de la page d'accueil (sans lui : le premier).
   Si le script ne charge pas, la boutique reste utilisable de bout en bout.
   ============================================================================ */
(function () {
  'use strict';

  var CART = 'ms_cart';

  function cookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function cartCount() {
    try {
      var c = JSON.parse(cookie(CART) || '{}');
      return Object.keys(c).reduce(function (n, k) { return n + (parseInt(c[k], 10) || 0); }, 0);
    } catch (e) { return null; }
  }

  function paintCount() {
    var n = cartCount();
    if (n === null) return;
    document.querySelectorAll('[data-cart-count]').forEach(function (el) {
      el.textContent = String(n);
      el.classList.toggle('is-zero', n === 0);
    });
  }

  // ---- Ajout au panier sans quitter la page --------------------------------
  // On poste le MÊME formulaire, en arrière-plan. Le serveur reste seul juge
  // du contenu du panier ; on ne fait que lui éviter un rechargement.
  /* Ce que le compteur de l'en-tête ne dit pas assez fort.
     Un chiffre qui passe de 0 à 1 en haut à droite est invisible sur un
     téléphone : le pouce est en bas, le regard sur le bouton. On confirme
     donc à l'endroit où l'on a cliqué, et on offre le chemin vers le panier
     — sans quoi il faut aller le chercher dans l'en-tête. */
  function confirmAdded(form) {
    var host = form.parentNode;
    if (!host) return;
    var note = host.querySelector('.added-note');
    if (!note) {
      var cart = document.querySelector('a.cart-btn');
      note = document.createElement('p');
      note.className = 'added-note';
      note.setAttribute('role', 'status');
      note.appendChild(document.createTextNode(document.body.getAttribute('data-added') || '✓'));
      if (cart) {
        var a = document.createElement('a');
        a.href = cart.getAttribute('href');
        var lbl = cart.querySelector('.cart-label');
        a.textContent = (lbl ? lbl.textContent : '').trim() || 'Koszyk';
        a.textContent += ' →';
        note.appendChild(document.createTextNode(' '));
        note.appendChild(a);
      }
      host.insertBefore(note, form.nextSibling);
    }
    note.hidden = false;
  }

  // La quantité qu'un formulaire d'achat envoie : la carte cochée sur la
  // fiche produit (« ile » = 1, 11, 25…), sinon le champ « qty ».
  function ileFormularza(form) {
    var r = form.querySelector('input[name="ile"]:checked');
    if (r && /^\d+$/.test(r.value)) return parseInt(r.value, 10);
    var q = form.querySelector('input[name="qty"]');
    return Math.max(1, parseInt(q && q.value, 10) || 1);
  }

  // DÉLÉGUÉ AU DOCUMENT, pas posé formulaire par formulaire : les
  // suggestions du panier sont redessinées après chaque « + » (plus bas), et
  // un écouteur posé sur l'ancien formulaire partirait avec lui.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches || !form.matches('form[data-add]')) return;
    if (!window.fetch) return;                         // navigateur ancien : POST classique
    ev.preventDefault();
    // Le bouton du formulaire ET ceux qui le visent de l'extérieur (la barre
    // d'achat du bas, form="kup-form") : tous se figent pendant l'envoi —
    // sinon deux pressions sur la barre ajoutent deux fois la quantité.
    var btns = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"]'));
    if (form.id) btns = btns.concat(Array.prototype.slice.call(document.querySelectorAll('button[form="' + form.id + '"]')));
    var labels = btns.map(function (b) { return b.textContent; });
    btns.forEach(function (b) { b.disabled = true; });
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' },
      redirect: 'follow'
    }).then(function () {
      paintCount();
      var done = document.body.getAttribute('data-added') || '✓';
      btns.forEach(function (b) { b.textContent = done; });
      setTimeout(function () {
        btns.forEach(function (b, k) { b.textContent = labels[k]; b.disabled = false; });
      }, 1400);
      confirmAdded(form);
      // Ce qui vient d'entrer compte pour les paliers : la fiche repart du
      // nouveau poids du panier (boîte, cartes, total), et le panier se
      // redessine.
      var box = form.querySelector('[data-prog]');
      if (box) {
        var plus = ileFormularza(form) * (parseInt(box.getAttribute('data-waga'), 10) || 0);
        box.setAttribute('data-w-koszyku', String((parseInt(box.getAttribute('data-w-koszyku'), 10) || 0) + plus));
      }
      if (form._paint) form._paint();
      if (koszyk) koszyk.odswiez();
      // Pour la mesure (zgody.js) : elle n'écoute que si le visiteur a dit oui.
      try {
        var idp = form.querySelector('input[name="add"]');
        document.dispatchEvent(new CustomEvent('wsm:dodano', { detail: {
          id: idp ? idp.value : '', qty: ileFormularza(form),
          price: (parseInt(form.getAttribute('data-cena'), 10) || 0) / 100 } }));
      } catch (e) { /* navigateur sans CustomEvent : rien à mesurer */ }
    }).catch(function () {
      // Réseau indisponible : on laisse le formulaire partir normalement.
      btns.forEach(function (b) { b.disabled = false; });
      form.submit();
    });
  });

  // ---- Les paliers de remise : même écriture que le serveur (lib.php) ------
  // « 1 093,35 zł » : espaces fines insécables entre les milliers, comme zl().
  function zl(gr) {
    var t = (Math.max(0, gr) / 100).toFixed(2).split('.');
    return t[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f') + ',' + t[1] + '\u202fzł';
  }
  function kgTxt(g) {
    return (Math.max(0, g) / 1000).toFixed(2).replace(/\.?0+$/, '').replace('.', ',') + '\u00a0kg';
  }
  function procentTxt(p) {
    return (Math.round(p * 10) / 10).toString().replace('.', ',');
  }
  function stanProgu(progi, g) {
    var have = 0, next = null;
    progi.forEach(function (t) {
      if (g >= t.g) { if (t.p > have) have = t.p; }
      else if (!next) { next = t; }
    });
    return { have: have, next: next, missing: next ? next.g - g : 0,
             pct: next ? Math.max(0, Math.min(100, Math.floor(g * 100 / next.g))) : 100 };
  }
  // Le texte de Treści, ses accolades remplies, ce qui est entre crochets en
  // gras — avec des nœuds de texte : rien de ce qui vient de la base n'est
  // interprété comme du HTML.
  function zdanie(el, tpl, vals) {
    if (!el) return;
    while (el.firstChild) el.removeChild(el.firstChild);
    String(tpl || '').split(/\[([^\[\]]+)\]/).forEach(function (part, k) {
      Object.keys(vals).forEach(function (key) { part = part.split(key).join(vals[key]); });
      if (!part) return;
      if (k % 2) { var b = document.createElement('b'); b.textContent = part; el.appendChild(b); }
      else el.appendChild(document.createTextNode(part));
    });
  }

  // ---- Fiche produit : le bloc d'achat suit la quantité --------------------
  // Le total (et celui de la barre du bas) pour TOUT produit ; pour un produit
  // pesé avec des paliers dans Rabaty, aussi la boîte « Dodaj kolejne 10 kg,
  // aby uzyskać rabat 5 % » et le prix de chaque carte. Le serveur a écrit
  // l'état pour une pièce ; on le recalcule à chaque choix, avec le poids du
  // panier déjà rempli (data-w-koszyku).
  var kupForm = document.getElementById('kup-form');
  if (kupForm) (function (form) {
    var box = form.querySelector('[data-prog]');
    var progi = [];
    if (box) { try { progi = JSON.parse(box.getAttribute('data-progi') || '[]') || []; } catch (e) { progi = []; } }
    var waga = box ? (parseInt(box.getAttribute('data-waga'), 10) || 0) : 0;
    var qty = form.querySelector('input[name="qty"]');
    var cena = parseInt(form.getAttribute('data-cena'), 10) || 0;
    var razem = document.querySelector('[data-kup-razem]');
    var razemPasek = document.querySelector('[data-kup-pasek-razem]');
    var oszcz = document.querySelector('[data-kup-oszczednosc]');
    var opcje = form.querySelector('.ile-opcje');
    var pasek = box && box.querySelector('[data-prog-pasek]');
    var ma = box && box.querySelector('[data-prog-ma]');
    var kosz = box && box.querySelector('[data-prog-kosz]');
    // Le même calcul que le serveur (cartes et panier) : arrondi au grosz.
    function poRabacie(n, wKosz) {
      var p = stanProgu(progi, wKosz + n * waga).have;
      return { gr: Math.round(cena * n * (1 - p / 100)), p: p };
    }
    function paint() {
      var wKosz = box ? (parseInt(box.getAttribute('data-w-koszyku'), 10) || 0) : 0;
      var max = qty ? (parseInt(qty.getAttribute('max'), 10) || 999) : 999;
      var n = Math.max(1, Math.min(max, ileFormularza(form)));
      if (cena) {
        var pelna = cena * n, teraz = poRabacie(n, wKosz).gr;
        if (razem) razem.textContent = zl(teraz);
        if (razemPasek) razemPasek.textContent = zl(teraz);
        if (oszcz) {
          oszcz.hidden = teraz >= pelna;
          oszcz.textContent = (oszcz.getAttribute('data-t') || '').split('{x}').join(zl(pelna - teraz));
        }
        // Les cartes : leur prix et leur insigne suivent le poids du panier.
        form.querySelectorAll('.ile-opcja[data-n]').forEach(function (karta) {
          var k = parseInt(karta.getAttribute('data-n'), 10) || 1, r = poRabacie(k, wKosz);
          var c = karta.querySelector('[data-ile-cena]'), em = karta.querySelector('[data-ile-rabat]');
          if (c) c.textContent = zl(r.gr);
          if (em) {
            em.hidden = r.p <= 0;
            em.textContent = r.p > 0 ? ((opcje && opcje.getAttribute('data-t-badge')) || 'rabat {p} %').split('{p}').join(procentTxt(r.p)) : '';
          }
        });
      }
      if (!box || !progi.length) return;
      var st = stanProgu(progi, wKosz + n * waga);
      zdanie(box.querySelector('[data-prog-zdanie]'),
             box.getAttribute(st.next ? 'data-t-next' : 'data-t-top'),
             { '{x}': kgTxt(st.missing), '{p}': procentTxt(st.next ? st.next.p : st.have) });
      if (pasek) pasek.style.width = st.pct + '%';
      if (ma) {
        ma.hidden = !(st.next && st.have > 0);
        ma.textContent = (box.getAttribute('data-t-have') || '').split('{p}').join(procentTxt(st.have));
      }
      if (kosz) {
        kosz.hidden = wKosz <= 0;
        kosz.textContent = (box.getAttribute('data-t-cart') || '').split('{k}').join(kgTxt(wKosz));
      }
    }
    if (qty) {
      qty.addEventListener('input', paint); qty.addEventListener('change', paint);
      // Taper une quantité, c'est choisir « Inna ilość ».
      qty.addEventListener('focus', function () {
        var inna = form.querySelector('input[name="ile"][value="inna"]');
        if (inna && !inna.checked) { inna.checked = true; paint(); }
      });
    }
    form.querySelectorAll('input[name="ile"]').forEach(function (r) {
      r.addEventListener('change', function () {
        // Une carte choisie remet « Inna ilość » à 1 : le serveur donne la
        // priorité au champ dès qu'il dit autre chose (pour le cas sans JS).
        if (/^\d+$/.test(r.value) && qty) qty.value = '1';
        paint();
      });
    });
    form._paint = paint;
    paint();
  })(kupForm);

  // ---- Caisse : n'afficher que les champs réellement exigés ----------------
  //
  // ON LIT LE TYPE DE SERVICE, PAS LE NOM DE LA MÉTHODE. La ligne d'avant
  // testait « r.value === 'inpost_courier' ». Avec un deuxième transporteur ce
  // test est faux pour lui : le client choisit le coursier DPD et la caisse
  // lui montre le champ « Paczkomat » en cachant l'adresse — il n'a alors
  // aucun endroit où écrire où livrer. Le serveur pose le type sur chaque
  // bouton (data-kind), parce que lui seul le connaît.
  var locker = document.querySelector('[data-ship-locker]');
  var courier = document.querySelector('[data-ship-courier]');
  document.querySelectorAll('input[data-ship]').forEach(function (r) {
    r.addEventListener('change', function () {
      if (!locker || !courier) return;
      var punkt = r.getAttribute('data-kind') === 'punkt';
      locker.hidden = !punkt;
      courier.hidden = punkt;
    });
  });

  var inv = document.querySelector('input[data-invoice]');
  var invFields = document.querySelector('[data-invoice-fields]');
  if (inv && invFields) {
    inv.addEventListener('change', function () { invFields.hidden = !inv.checked; });
  }

  // ---- Caisse : changer de pays change les transporteurs ET la TVA ---------
  // On recharge la page avec le pays choisi plutôt que de recalculer côté
  // client : le prix ne se décide pas dans le navigateur.
  var country = document.querySelector('select[data-country]');
  if (country) {
    country.addEventListener('change', function () {
      var q = new URLSearchParams(location.search);
      q.set('kraj', country.value);
      location.search = q.toString();
    });
  }

  // ---- Paczkomat : choisir sur la carte plutôt que taper un code ------------
  //
  // Le bloc est rendu `hidden` par le serveur et RÉVÉLÉ ici : sans JavaScript,
  // un bouton « choisir sur la carte » qui n'ouvre rien serait pire que pas de
  // bouton du tout. Le champ texte reste la source de vérité — la carte ne
  // fait que le remplir, et on peut toujours corriger à la main.
  var geo = document.querySelector('[data-geo]');
  if (geo) {
    var champ = document.querySelector('#f-inpost_point');
    var boite = geo.querySelector('[data-geo-box]');
    var choisi = geo.querySelector('[data-geo-chosen]');
    geo.hidden = false;

    var souci = geo.querySelector('[data-geo-fail]');
    var aide = geo.querySelector('[data-geo-help]');

    geo.querySelector('[data-geo-open]').addEventListener('click', function () {
      boite.hidden = !boite.hidden;
      // LA SORTIE DE SECOURS SUIT LA CARTE, ET NE JUGE RIEN.
      //
      // Le repli plus bas ne se déclenche que si la carte n'arrive pas. Mais
      // elle peut très bien arriver et refuser : « Brak dostępu, sprawdź czy
      // token został wygenerowany dla odpowiedniej witryny » — le composant est
      // défini, la boîte a sa hauteur, et rien ne bronche. Le client reste
      // devant un refus sans savoir que le champ au-dessus marche toujours.
      //
      // On ne tente pas de lire leur message : il est chez eux, en trois
      // langues, et il changera. On montre la sortie tant que la carte est
      // ouverte — utile quand elle marche, salutaire quand elle refuse.
      if (aide) aide.hidden = boite.hidden;
      if (boite.hidden) return;

      // LE SCRIPT D'INPOST VIENT D'UN AUTRE DOMAINE, ET IL ARRIVE QU'IL NE
      // VIENNE PAS : bloqueur de publicité, réseau d'entreprise, panne chez
      // eux. On clique « choisir sur la carte » et il ne se passe RIEN.
      // On le dit, et on renvoie au champ texte qui, lui, marche toujours.
      //
      // ON DEMANDE AU NAVIGATEUR, ON NE MESURE PAS. Le premier jet regardait
      // la hauteur de la boîte : c'était juste tant que rien ne dimensionnait
      // le composant, et faux dès qu'on lui a donné une hauteur en CSS — la
      // balise inconnue prenait la hauteur, le repli mesurait 460 px et se
      // taisait. `customElements.get()` répond à la vraie question : le script
      // a-t-il défini le composant, oui ou non.
      setTimeout(function () {
        if (boite.hidden) return;
        var pret = window.customElements && window.customElements.get('inpost-geowidget');
        // Défini mais n'ayant rien dessiné compte aussi pour une panne.
        var vide = !pret || boite.getBoundingClientRect().height < 20;
        if (souci) souci.hidden = !vide;
        if (vide) {
          boite.hidden = true;
          // Un seul message à la fois : le repli DIT déjà de taper le code,
          // la note de secours le répéterait mot pour mot.
          if (aide) aide.hidden = true;
          if (champ) champ.focus();
        }
      }, 1800);
    });

    // Le composant InPost appelle cette fonction par son NOM (attribut
    // onpoint), pas par un écouteur : elle doit donc être globale.
    window.wsmGeoPoint = function (point) {
      var d = (point && point.detail) ? point.detail : point;
      var code = d && (d.name || d.id);
      if (!code) return;
      if (champ) {
        champ.value = code;
        // `input` et pas seulement l'affectation : la validation du navigateur
        // et tout écouteur en aval doivent voir la valeur changer.
        champ.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (choisi) {
        var adr = [d.address && d.address.line1, d.address && d.address.line2]
          .filter(Boolean).join(', ');
        choisi.textContent = code + (adr ? ' — ' + adr : '');
        choisi.hidden = false;
      }
      boite.hidden = true;
      // Le point est choisi : la carte se referme, et la note qui expliquait
      // comment s'en passer n'a plus d'objet.
      if (aide) aide.hidden = true;
    };
  }

  // ---- Fiche produit : la barre d'achat du bas -----------------------------
  // Elle n'apparaît qu'une fois le vrai bouton « Dodaj do koszyka » passé
  // au-dessus de l'écran : tant qu'il est visible, deux boutons identiques
  // font hésiter ; tant qu'il est plus bas, on n'a encore rien choisi.
  // Calculé au défilement et pas par IntersectionObserver : un saut (ancre,
  // retour sur la page) va de « sous l'écran » à « au-dessus » sans jamais le
  // croiser, et l'observateur ne se déclenchait pas — la barre restait cachée.
  var pasekKup = document.querySelector('[data-kup-pasek]');
  var glowny = document.querySelector('#kup-form button[type="submit"]');
  if (pasekKup && glowny) {
    var czeka = false;
    var ustaw = function () {
      czeka = false;
      pasekKup.classList.toggle('is-ukryty', glowny.getBoundingClientRect().bottom > 0);
    };
    var zaplanuj = function () { if (!czeka) { czeka = true; window.requestAnimationFrame(ustaw); } };
    window.addEventListener('scroll', zaplanuj, { passive: true });
    window.addEventListener('resize', zaplanuj);
    ustaw();
  }

  // ---- Zapłać : le formulaire Stripe, intégré dans la page ------------------
  // Stripe.js (chargé par la page, version épinglée « endive ») monte son
  // formulaire dans un iframe ICI : BLIK, Przelewy24, carte, portefeuilles.
  // La boutique ne voit jamais une donnée de carte. Le secret de session est
  // demandé au serveur au moment du montage, avec le jeton du formulaire ;
  // la commande et son montant sont fixés là-bas, jamais ici.
  var stripeBox = document.querySelector('[data-stripe-checkout]');
  if (stripeBox) {
    var montuj = function () {
      if (!window.Stripe) return false;
      var stripe = window.Stripe(stripeBox.getAttribute('data-pk'),
        { stripeAccount: stripeBox.getAttribute('data-konto') || undefined });
      var blad = function () {
        while (stripeBox.firstChild) stripeBox.removeChild(stripeBox.firstChild);
        var p = document.createElement('p');
        p.className = 'notice notice--warn';
        p.textContent = stripeBox.getAttribute('data-blad') || 'Error';
        stripeBox.appendChild(p);
      };
      var fetchClientSecret = function () {
        var fd = new FormData();
        fd.append('_t', stripeBox.getAttribute('data-t') || '');
        return fetch(stripeBox.getAttribute('data-sesja'), {
          method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
        }).then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        }).then(function (d) {
          if (!d || !d.clientSecret) throw new Error('no secret');
          return d.clientSecret;
        });
      };
      // La méthode a changé de nom avec la version « dahlia » de Stripe.js ;
      // on prend celle qui existe, la nouvelle d'abord.
      var cree = stripe.createEmbeddedCheckoutPage || stripe.initEmbeddedCheckout;
      if (!cree) { blad(); return true; }
      cree.call(stripe, { fetchClientSecret: fetchClientSecret }).then(function (checkout) {
        while (stripeBox.firstChild) stripeBox.removeChild(stripeBox.firstChild);
        checkout.mount(stripeBox);
      }).catch(blad);
      return true;
    };
    // Le script de Stripe est chargé en « defer » : il peut arriver après nous.
    if (!montuj()) {
      var essais = 0;
      var attente = setInterval(function () {
        if (montuj() || ++essais > 100) {
          clearInterval(attente);
          if (!window.Stripe) {
            stripeBox.textContent = stripeBox.getAttribute('data-blad') || '';
          }
        }
      }, 100);
    }
  }

  // ---- Le menu sur téléphone -------------------------------------------------
  var menuBtn = document.querySelector('.head-menu');
  var head = document.querySelector('.site-head');
  if (menuBtn && head) {
    menuBtn.hidden = false;
    menuBtn.addEventListener('click', function () {
      var otwarte = head.classList.toggle('is-open');
      menuBtn.setAttribute('aria-expanded', otwarte ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && head.classList.contains('is-open')) {
        head.classList.remove('is-open'); menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.focus();
      }
    });
  }

  // ---- Panier : tout se recalcule sur place ------------------------------
  //
  // « − », « + », une quantité tapée, la corbeille, le mode de livraison, le
  // code : chaque geste part au serveur EN ARRIÈRE-PLAN, et la réponse — la
  // même page, rendue par le même code — remplace le contenu de <main>. Le
  // navigateur ne calcule rien : les prix, la remise et le port viennent du
  // devis du serveur, comme sans JavaScript.
  //
  // Les clics rapprochés sur « + » sont regroupés (350 ms) et partent en UNE
  // requête qui porte TOUTES les quantités affichées : le panier vit dans un
  // cookie, et deux requêtes croisées pourraient sinon se marcher dessus.
  var koszyk = null;
  var zywy = document.querySelector('[data-koszyk]');
  var main = zywy && zywy.closest('main');
  if (main && window.fetch && window.DOMParser) {
    var enCours = null, minuterie = null;

    var cle = function (el) {
      if (!el || !el.name || !main.contains(el)) return null;
      var esc = window.CSS && CSS.escape ? CSS.escape : function (v) { return String(v).replace(/["\\]/g, '\\$&'); };
      return el.tagName + '[name="' + esc(el.name) + '"]' + (el.type === 'number' || el.type === 'text' ? '' : '[value="' + esc(el.value) + '"]');
    };

    var remplace = function (url, init, repli) {
      var z = main.querySelector('[data-koszyk]');
      if (z) z.classList.add('is-busy');
      var avant = {};
      main.querySelectorAll('[data-pasek]').forEach(function (i) { avant[i.getAttribute('data-pasek')] = i.style.width; });
      var focus = cle(document.activeElement);
      if (enCours && enCours.abort) enCours.abort();
      enCours = window.AbortController ? new AbortController() : null;
      init = init || {};
      init.credentials = 'same-origin';
      init.headers = { 'X-Requested-With': 'fetch' };
      if (enCours) init.signal = enCours.signal;
      fetch(url, init).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text().then(function (html) { return { html: html, url: r.url }; });
      }).then(function (o) {
        var doc = new DOMParser().parseFromString(o.html, 'text/html');
        var nowy = doc.querySelector('main');
        if (!nowy) throw new Error('main');
        main.innerHTML = nowy.innerHTML;
        try { if (o.url && o.url !== location.href) history.replaceState(null, '', o.url); } catch (e) {}
        // Les barres repartent de leur ancienne largeur : on VOIT le seuil
        // se rapprocher, au lieu d'un saut.
        main.querySelectorAll('[data-pasek]').forEach(function (i) {
          var k = i.getAttribute('data-pasek');
          if (avant[k] === undefined) return;
          var w = i.style.width;
          i.style.width = avant[k];
          void i.offsetWidth;
          i.style.width = w;
        });
        if (focus) {
          var f = main.querySelector(focus);
          if (f && !f.disabled) f.focus({ preventScroll: true });
        }
        paintCount();
      }).catch(function (e) {
        if (e && e.name === 'AbortError') return;
        repli();
      });
    };

    var envoie = function (form, submitter) {
      var fd;
      try { fd = submitter ? new FormData(form, submitter) : new FormData(form); }
      catch (e) {
        fd = new FormData(form);
        if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
      }
      var repli = function () {
        if (submitter && submitter.name) {
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
          form.appendChild(h);
        }
        form.submit();
      };
      if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') {
        remplace(form.action, { method: 'POST', body: fd }, repli);
      } else {
        var url = form.action + (form.action.indexOf('?') < 0 ? '?' : '&') + new URLSearchParams(fd).toString();
        remplace(url, { method: 'GET' }, function () { location.href = url; });
      }
    };

    main.addEventListener('click', function (ev) {
      var b = ev.target.closest && ev.target.closest('.stepper-btn');
      if (!b || !main.contains(b)) return;
      ev.preventDefault();
      var input = b.parentNode.querySelector('input[type="number"]');
      if (!input) return;
      var max = parseInt(input.getAttribute('max'), 10) || 999;
      var v = Math.max(0, Math.min(max, (parseInt(input.value, 10) || 0) + (b.value === '-1' ? -1 : 1)));
      input.value = String(v);
      clearTimeout(minuterie);
      minuterie = setTimeout(function () { envoie(input.form, null); }, 350);
    });

    main.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.matches('[data-od-razu]')) { envoie(t.form, null); return; }
      if (t.matches('.cart-line input[type="number"]')) {
        clearTimeout(minuterie);
        minuterie = setTimeout(function () { envoie(t.form, null); }, 250);
      }
    });

    main.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (!form.matches('form[data-koszyk-form]')) return;
      ev.preventDefault();
      clearTimeout(minuterie);
      envoie(form, ev.submitter || null);
    });

    koszyk = { odswiez: function () { remplace(location.href, { method: 'GET' }, function () { location.reload(); }); } };
  }

  // ---- Les slajdy du haut de la page d'accueil ----------------------------
  //
  // Le défilement automatique se règle dans la console (Slajdy) : marche ou
  // arrêt, et le temps par slajd. Il s'arrête quand la souris ou le clavier
  // est dessus (on lit), quand l'onglet est caché, et il ne démarre pas du
  // tout pour qui a demandé moins d'animations à son système. Le bouton
  // pause est là pour les autres : un contenu qui bouge seul doit pouvoir
  // être arrêté.
  document.querySelectorAll('[data-slajdy]').forEach(function (box) {
    var slajdy = box.querySelectorAll('[data-slajd]');
    if (slajdy.length < 2) return;
    var tor = box.querySelector('.slajdy-tor');
    var kropki = box.querySelectorAll('[data-slajdy-idz]');
    var nav = box.querySelector('[data-slajdy-nav]');
    var pauza = box.querySelector('[data-slajdy-pauza]');
    var co = parseInt(box.getAttribute('data-co'), 10) || 5000;
    var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var auto = box.getAttribute('data-auto') === '1' && !calme;
    var i = 0, minut = null, arret = false, dessus = false;

    box.classList.add('is-zywe');
    slajdy.forEach(function (s) { s.hidden = false; });
    if (nav) nav.hidden = false;
    if (pauza && !auto) pauza.hidden = true;

    var stop = function () { clearTimeout(minut); minut = null; };
    var start = function () {
      stop();
      if (tor) tor.setAttribute('aria-live', auto && !arret ? 'off' : 'polite');
      if (auto && !arret && !dessus && !document.hidden) minut = setTimeout(function () { idz(i + 1); }, co);
    };
    var idz = function (n) {
      i = (n + slajdy.length) % slajdy.length;
      slajdy.forEach(function (s, k) { s.classList.toggle('is-on', k === i); });
      kropki.forEach(function (d, k) {
        if (k === i) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current');
      });
      start();
    };

    box.addEventListener('mouseenter', function () { dessus = true; stop(); });
    box.addEventListener('mouseleave', function () { dessus = false; start(); });
    box.addEventListener('focusin', function () { dessus = true; stop(); });
    box.addEventListener('focusout', function (ev) {
      if (!box.contains(ev.relatedTarget)) { dessus = false; start(); }
    });
    document.addEventListener('visibilitychange', start);

    var wstecz = box.querySelector('[data-slajdy-wstecz]');
    var dalej = box.querySelector('[data-slajdy-dalej]');
    if (wstecz) wstecz.addEventListener('click', function () { idz(i - 1); });
    if (dalej) dalej.addEventListener('click', function () { idz(i + 1); });
    kropki.forEach(function (d) {
      d.addEventListener('click', function () { idz(parseInt(d.getAttribute('data-slajdy-idz'), 10) || 0); });
    });
    if (pauza) {
      pauza.setAttribute('aria-pressed', 'false');
      pauza.addEventListener('click', function () {
        arret = !arret;
        pauza.setAttribute('aria-pressed', arret ? 'true' : 'false');
        pauza.setAttribute('aria-label', pauza.getAttribute(arret ? 'data-t-graj' : 'data-t-pauza') || '');
        start();
      });
    }

    // Au doigt : un glissement horizontal change de slajd.
    var x0 = null;
    box.addEventListener('pointerdown', function (ev) { if (ev.pointerType !== 'mouse') x0 = ev.clientX; });
    box.addEventListener('pointerup', function (ev) {
      if (x0 === null) return;
      var dx = ev.clientX - x0;
      x0 = null;
      if (Math.abs(dx) > 50) idz(i + (dx < 0 ? 1 : -1));
    });

    start();
  });

  paintCount();
})();
