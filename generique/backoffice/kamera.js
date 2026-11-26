/* ============================================================================
   kamera.js — lire un code EAN avec l'appareil photo du téléphone.
   Partagé par skaner.php et pakowanie.php.

   Deux façons, choisies selon ce que le navigateur permet :

   1. « 📷 Zdjęcie kodu » — MARCHE PARTOUT, iPhone compris, en http comme en
      https : le champ fichier ouvre l'appareil photo (capture="environment"),
      on photographie le code, la page le lit dans la photo. Pas besoin du flux
      vidéo, que les navigateurs refusent sur une adresse http (http://<IP>/…).

   2. « 🎥 Skanuj na żywo » — seulement sur une adresse https (contexte
      sécurisé) : la vidéo défile, le code est lu dès qu'il passe devant.

   Le décodage : BarcodeDetector quand le navigateur l'a (Chrome / Android),
   sinon ZXing (_vendor/zxing.min.js, licence MIT), chargé à la première
   utilisation seulement — 330 Ko qu'on ne fait pas payer à qui scanne au
   lecteur USB.
   ============================================================================ */
(function () {
  var FORMATS_BD = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'code_128'];
  var zxingPromesse = null;

  function chargeZxing() {
    if (window.ZXing) return Promise.resolve(window.ZXing);
    if (zxingPromesse) return zxingPromesse;
    zxingPromesse = new Promise(function (ok, ko) {
      var s = document.createElement('script');
      s.src = '_vendor/zxing.min.js';
      s.onload = function () { window.ZXing ? ok(window.ZXing) : ko(new Error('ZXing')); };
      s.onerror = function () { zxingPromesse = null; ko(new Error('ZXing')); };
      document.head.appendChild(s);
    });
    return zxingPromesse;
  }

  function indices(Z) {
    var h = new Map();
    var F = Z.BarcodeFormat;
    h.set(Z.DecodeHintType.POSSIBLE_FORMATS, [F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.ITF, F.CODE_128]);
    h.set(Z.DecodeHintType.TRY_HARDER, true);
    return h;
  }

  // Une photo de téléphone fait 12 Mpx : on la réduit, et on essaie aussi
  // tournée d'un quart (code photographié de travers, photo en portrait).
  function toile(img, max, tourne) {
    var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
    var k = Math.min(1, max / Math.max(w, h));
    var W = Math.round(w * k), H = Math.round(h * k);
    var c = document.createElement('canvas');
    c.width = tourne ? H : W; c.height = tourne ? W : H;
    var x = c.getContext('2d');
    if (tourne) { x.translate(H, 0); x.rotate(Math.PI / 2); }
    x.drawImage(img, 0, 0, W, H);
    return c;
  }

  function lisPhotoZxing(img) {
    return chargeZxing().then(function (Z) {
      var lecteur = new Z.MultiFormatReader();
      lecteur.setHints(indices(Z));
      var essais = [[1600, false], [1600, true], [1000, false], [1000, true], [2400, false]];
      for (var i = 0; i < essais.length; i++) {
        try {
          var c = toile(img, essais[i][0], essais[i][1]);
          var bm = new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(c)));
          var r = lecteur.decode(bm);
          if (r && r.getText()) return r.getText();
        } catch (e) { /* pas trouvé dans cet essai : on continue */ }
      }
      return '';
    });
  }

  function lisPhoto(fichier) {
    return new Promise(function (ok, ko) {
      var url = URL.createObjectURL(fichier), img = new Image();
      img.onload = function () {
        var fini = function (t) { URL.revokeObjectURL(url); ok(t || ''); };
        if ('BarcodeDetector' in window) {
          new BarcodeDetector({ formats: FORMATS_BD }).detect(img)
            .then(function (k) { return k.length ? k[0].rawValue : lisPhotoZxing(img); })
            .then(fini, function () { lisPhotoZxing(img).then(fini, function () { fini(''); }); });
        } else {
          lisPhotoZxing(img).then(fini, function (e) { URL.revokeObjectURL(url); ko(e); });
        }
      };
      img.onerror = function () { URL.revokeObjectURL(url); ko(new Error('obraz')); };
      img.src = url;
    });
  }

  /**
   * Branche les boutons d'une page.
   * o = { zdjecie: bouton, naZywo: bouton, wideo: <video>, kod: function(texte), blad: function(message) }
   */
  window.wsmKamera = function (o) {
    // 1. La photo : toujours proposée.
    var plik = document.createElement('input');
    plik.type = 'file'; plik.accept = 'image/*'; plik.setAttribute('capture', 'environment');
    plik.style.display = 'none';
    document.body.appendChild(plik);
    o.zdjecie.hidden = false;
    o.zdjecie.addEventListener('click', function () { plik.value = ''; plik.click(); });
    plik.addEventListener('change', function () {
      var f = plik.files && plik.files[0];
      if (!f) return;
      var etiquette = o.zdjecie.textContent;
      o.zdjecie.disabled = true; o.zdjecie.textContent = '⏳ Czytam kod…';
      lisPhoto(f).then(function (t) {
        var k = (t || '').replace(/\D+/g, '');
        if (k) o.kod(k);
        else o.blad('Nie widzę kodu na zdjęciu. Zrób zdjęcie z bliska, kod poziomo, ostro i bez odblasku.');
      }, function () {
        o.blad('Nie udało się odczytać zdjęcia. Spróbuj jeszcze raz.');
      }).then(function () { o.zdjecie.disabled = false; o.zdjecie.textContent = etiquette; });
    });

    // 2. La vidéo en direct : seulement en contexte sécurisé (https).
    if (!o.naZywo || !o.wideo) return;
    if (!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) return;
    o.naZywo.hidden = false;
    var flux = null, lecteurZ = null, dernier = '', quand = 0;
    function trouve(t) {
      var k = (t || '').replace(/\D+/g, '');
      // Le même code vu en continu ne compte qu'une fois par 1,5 s.
      if (k && (k !== dernier || Date.now() - quand > 1500)) { dernier = k; quand = Date.now(); o.kod(k); }
    }
    function arrete() {
      if (lecteurZ) { try { lecteurZ.reset(); } catch (e) {} lecteurZ = null; }
      if (flux) { flux.getTracks().forEach(function (t) { t.stop(); }); flux = null; }
      o.wideo.style.display = 'none'; o.naZywo.textContent = '🎥 Skanuj na żywo';
    }
    o.naZywo.addEventListener('click', function () {
      if (flux || lecteurZ) { arrete(); return; }
      o.naZywo.textContent = '■ Zatrzymaj';
      o.wideo.style.display = 'block';
      if ('BarcodeDetector' in window) {
        var det = new BarcodeDetector({ formats: FORMATS_BD });
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
          flux = s; o.wideo.srcObject = s; o.wideo.play();
          (function boucle() {
            if (!flux) return;
            det.detect(o.wideo).then(function (k) { if (k.length) trouve(k[0].rawValue); })
              .catch(function () {}).finally(function () { setTimeout(boucle, 200); });
          })();
        }).catch(function () { arrete(); o.blad('Brak dostępu do aparatu — zezwól przeglądarce na użycie aparatu.'); });
      } else {
        chargeZxing().then(function (Z) {
          lecteurZ = new Z.BrowserMultiFormatReader(indices(Z), 250);
          return lecteurZ.decodeFromConstraints({ video: { facingMode: 'environment' } }, o.wideo, function (r) {
            if (r) trouve(r.getText());
          });
        }).catch(function () { arrete(); o.blad('Brak dostępu do aparatu — zezwól przeglądarce na użycie aparatu.'); });
      }
    });
  };

  /**
   * Un champ « code EAN » de formulaire (fiche produit) : un bouton 📷 à côté
   * remplit le champ avec le code lu sur la photo — et la vidéo en direct en https.
   */
  window.wsmKameraPole = function (champ) {
    if (!champ || champ.disabled || champ.dataset.kamera) return;
    champ.dataset.kamera = '1';
    var boite = document.createElement('span'); boite.className = 'kamera-pole';
    var zd = document.createElement('button'); zd.type = 'button'; zd.hidden = true; zd.textContent = '📷 Zeskanuj aparatem';
    var nz = document.createElement('button'); nz.type = 'button'; nz.hidden = true; nz.textContent = '🎥 Skanuj na żywo';
    var wi = document.createElement('video'); wi.setAttribute('playsinline', ''); wi.muted = true;
    wi.style.cssText = 'display:none;width:100%;max-height:220px;border-radius:10px;margin-top:6px;background:#000;object-fit:cover';
    var info = document.createElement('small'); info.className = 'kamera-info';
    boite.appendChild(zd); boite.appendChild(nz);
    champ.insertAdjacentElement('afterend', boite);
    boite.insertAdjacentElement('afterend', wi);
    wi.insertAdjacentElement('afterend', info);
    window.wsmKamera({ zdjecie: zd, naZywo: nz, wideo: wi,
      kod: function (k) {
        champ.value = k; info.textContent = '✓ Odczytano kod ' + k + ' — zapisz produkt.'; info.style.color = '';
        champ.dispatchEvent(new Event('input', { bubbles: true })); champ.dispatchEvent(new Event('change', { bubbles: true }));
        if (wi.style.display !== 'none') nz.click();
      },
      blad: function (m) { info.textContent = m; info.style.color = 'var(--danger, #b42318)'; } });
  };
})();
