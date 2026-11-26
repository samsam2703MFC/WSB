/* adapt.js — Ola flora : relie les écrans du projet (tels quels) aux tables SQL.
   Chargé en tête de page, AVANT support.js. Rien n'est réécrit dans les
   fichiers : au chargement seulement,
   1. les données de l'écran sont lues sur le serveur (synchrone) ;
   2. le code de l'écran est retouché en mémoire : chaque champ fourni par le
      serveur (ex. ZAP, DOSS) prend la valeur de la base au lieu de l'exemple
      de la maquette ; une couche glissée sous la classe signale les gestes
      (ex. « Wysyłam » du formulaire) pour les enregistrer. */
(function () {
  var nom = decodeURIComponent(location.pathname.split('/').pop() || '');
  var ekran = (nom === '' || nom === 'index.html') ? 'Ola flora - wesela v2' : nom.replace(/\.dc\.html$/, '');
  var prive = /^(Back office|Poczta|Super admin|Asystent rozmowy|Retroplan|Karta misji)/.test(ekran);
  var base = (document.currentScript && document.currentScript.src) ? document.currentScript.src.replace(/adapt\.js(\?.*)?$/, '') : '_wsb/';
  var dane = {};
  if (prive) {
    try {
      var x = new XMLHttpRequest();
      x.open('GET', base + 'prive/app/dane.php?ekran=' + encodeURIComponent(ekran), false);
      x.send(null);
      if (x.status === 200) dane = JSON.parse(x.responseText || '{}') || {};
    } catch (e) { /* hors ligne : les exemples de la maquette restent */ }
  }
  window.__of_dane = dane;

  function poster(url, corps, ok) {
    var y = new XMLHttpRequest();
    y.open('POST', base + url, true);
    y.setRequestHeader('Content-Type', 'application/json');
    y.onload = function () { try { ok && ok(JSON.parse(y.responseText || '{}'), y.status); } catch (e) {} };
    y.send(JSON.stringify(corps));
  }

  // Les gestes à enregistrer, par écran.
  function geste(c) {
    var s = c.state || {};
    if (ekran === 'FormularzWizard' && s.done && !c.__ofWyslane) {
      c.__ofWyslane = true;
      poster('app/zapytanie.php', { vals: s.vals || {}, multi: s.multi || [], palette: s.palette || '', accents: s.accents || [] });
    }
    if (!s.done && c.__ofWyslane && ekran === 'FormularzWizard') c.__ofWyslane = false;   // « od nowa »
  }

  window.__ofKlasa = function (Base) {
    return class extends Base {
      setState(u, cb) {
        var self = this;
        return super.setState(u, function () { if (cb) cb(); try { geste(self); } catch (e) {} });
      }
    };
  };

  document.addEventListener('DOMContentLoaded', function () {
    var el = document.querySelector('script[data-dc-script]');
    if (!el) return;
    var js = el.textContent;
    js = js.replace(/class\s+Component\s+extends\s+DCLogic\b/, 'class Component extends window.__ofKlasa(DCLogic)');
    Object.keys(dane).forEach(function (k) {
      if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(k)) return;
      js = js.replace(new RegExp('^(\\s{2})' + k + '\\s*=\\s*', 'm'),
        '$1' + k + ' = Object.prototype.hasOwnProperty.call(window.__of_dane, "' + k + '") ? window.__of_dane["' + k + '"] : ');
    });
    el.textContent = js;
  });
})();
