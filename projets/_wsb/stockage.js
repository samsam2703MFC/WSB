/* stockage.js — ce que la page range dans le navigateur (localStorage) va dans
   la base MySQL du projet. Chargé AVANT tout autre script de la page : la
   lecture est synchrone, pour que le code de la page trouve ses données au
   premier getItem, comme avant. La page elle-même n'est pas modifiée. */
(function () {
  if (!window.localStorage || !window.XMLHttpRequest) return;
  var nom = decodeURIComponent((location.pathname.split('/').pop() || ''));
  var prive = /^(Back office|Poczta|Super admin|Asystent rozmowy|Retroplan|Karta misji)/.test(nom);
  var base = (document.currentScript && document.currentScript.src) ? document.currentScript.src.replace(/stockage\.js(\?.*)?$/, '') : '_wsb/';
  var API = base + (prive ? 'prive/' : '') + 'stockage.php';
  var ls = window.localStorage, P = Storage.prototype;
  var set = P.setItem, rem = P.removeItem, clr = P.clear;
  try {
    var x = new XMLHttpRequest();
    x.open('GET', API, false);
    x.send(null);
    if (x.status === 200) {
      var d = JSON.parse(x.responseText || '{}');
      var cles = d.cles || {}, gere = d.gere || [];
      // Le serveur fait foi : ses clés remplacent la copie du navigateur, et une
      // clé qu'il gère mais n'a pas (effacée ailleurs) disparaît d'ici aussi.
      Object.keys(cles).forEach(function (k) { set.call(ls, k, cles[k]); });
      for (var i = ls.length - 1; i >= 0; i--) {
        var k = ls.key(i);
        if (k && !(k in cles) && gere.some(function (g) { return k === g || k.indexOf(g) === 0; })) rem.call(ls, k);
      }
    }
  } catch (e) { /* hors ligne : la copie du navigateur sert */ }
  var file = {}, minuterie = null;
  function envoyer() {
    minuterie = null;
    var lot = file; file = {};
    var y = new XMLHttpRequest();
    y.open('POST', API, true);
    y.setRequestHeader('Content-Type', 'application/json');
    y.send(JSON.stringify(lot));
  }
  function pousser(k, v) {
    file[k] = v;                                  // la dernière valeur d'une clé suffit
    if (!minuterie) minuterie = setTimeout(envoyer, 400);
  }
  window.addEventListener('pagehide', function () {
    if (!minuterie) return;
    clearTimeout(minuterie); minuterie = null;
    var lot = JSON.stringify(file); file = {};
    if (navigator.sendBeacon) navigator.sendBeacon(API, new Blob([lot], { type: 'application/json' }));
  });
  P.setItem = function (k, v) { set.call(this, k, v); if (this === ls) pousser(String(k), String(v)); };
  P.removeItem = function (k) { rem.call(this, k); if (this === ls) pousser(String(k), null); };
  P.clear = function () {
    if (this === ls) for (var i = 0; i < ls.length; i++) pousser(ls.key(i), null);
    clr.call(this);
  };
})();
