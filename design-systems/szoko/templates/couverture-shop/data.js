// ---- Mister Szoko Pro — B2B couverture webshop (prototype data) ----
// Prices are NETTO (HT). Brutto (TTC) = netto * (1 + vat). Prix/kg dérivé.
// Conditionnements dégressifs : 2,5 kg (plein tarif) / 10 kg (−12 %/kg) / 20 kg (−20 %/kg).

const EUR_RATE = 4.30; // PLN per EUR (affichage catalogue figé, pas de conversion live)
const FORMATS = [
  { key: 's25',  label: 'Worek 2,5 kg',  kg: 2.5, mult: 1.00 },
  { key: 's10',  label: 'Worek 10 kg',   kg: 10,  mult: 0.88 },
  { key: 'c20',  label: 'Karton 20 kg',  kg: 20,  mult: 0.80 },
];

function makeVariants(perKgPLN, stocks) {
  return FORMATS.map((f, i) => {
    const nettoPLN = Math.round(perKgPLN * f.kg * f.mult * 100) / 100;
    const nettoEUR = Math.round((nettoPLN / EUR_RATE) * 100) / 100;
    return {
      key: f.key, label: f.label, kg: f.kg,
      netto: { PLN: nettoPLN, EUR: nettoEUR },
      perKg: { PLN: Math.round(perKgPLN * f.mult * 100) / 100, EUR: Math.round((perKgPLN * f.mult / EUR_RATE) * 100) / 100 },
      stock: stocks[i],
      qty: stocks[i] === 'Dostępny' ? [140, 80, 32][i] : stocks[i] === 'Ostatnie sztuki' ? 6 : 0,
      arrival: stocks[i] === 'Dostępny' ? null : stocks[i] === 'Ostatnie sztuki' ? '24/07' : '28/07',
    };
  });
}

const P = (o) => ({ vat: 0.23, allergens: 'Mleko, soja. Może zawierać: orzechy, gluten.', ...o });

window.MS = {
  currencies: { PLN: { code: 'PLN', sym: 'zł', suffix: true }, EUR: { code: 'EUR', sym: '€', suffix: true } },
  langs: [ { c: 'pl', n: 'Polski' }, { c: 'en', n: 'English' }, { c: 'cs', n: 'Čeština' }, { c: 'uk', n: 'Українська' } ],
  categories: [
    { key: 'all', label: 'Cały katalog' },
    { key: 'dark', label: 'Czekolada ciemna' },
    { key: 'milk', label: 'Czekolada mleczna' },
    { key: 'white', label: 'Czekolada biała' },
    { key: 'special', label: 'Specjalności' },
  ],
  config: {
    freeShip: { PLN: 800, EUR: 186 },
    shipping: { PLN: 29, EUR: 6.9 },
    volumeTiers: [ { min: 3, pct: 5 }, { min: 5, pct: 8 }, { min: 8, pct: 12 } ], // par référence, cartons
  },
  products: [
    P({ id: 'noir70', brand: 'Callebaut', cat: 'dark', color: '#4a2c1a', name: 'Ciemna 70% — Ghana', cacao: '70 %', origin: 'Ghana', fluidity: 3,
      blurb: 'Uniwersalna ciemna kuwertura, wyrazisty i drzewny profil kakao. Średnia płynność — idealna do oblewania i formowania.',
      ingredients: 'Miazga kakaowa, cukier, tłuszcz kakaowy, emulgator (lecytyna słonecznikowa), wanilia.',
      variants: makeVariants(26.0, ['Dostępny', 'Dostępny', 'Ostatnie sztuki']) }),
    P({ id: 'noir54', brand: 'Callebaut', cat: 'dark', color: '#5a3826', name: 'Ciemna 54%', cacao: '54 %', origin: 'Mieszanka', fluidity: 4,
      blurb: 'Łagodna, okrągła ciemna czekolada o wysokiej płynności. Koń roboczy pracowni: ganache i praliny.',
      ingredients: 'Cukier, miazga kakaowa, tłuszcz kakaowy, emulgator (lecytyna słonecznikowa), wanilia.',
      variants: makeVariants(22.5, ['Dostępny', 'Dostępny', 'Dostępny']) }),
    P({ id: 'noir80', brand: 'Valrhona', cat: 'dark', color: '#3a2114', name: 'Ciemna 80% — Grand Cru', cacao: '80 %', origin: 'Madagascar', fluidity: 2,
      blurb: 'Intensywny grand cru z owocową kwasowością. Niska płynność: grube formy, tabliczki z charakterem.',
      ingredients: 'Miazga kakaowa, cukier, tłuszcz kakaowy, wanilia.',
      variants: makeVariants(32.0, ['Dostępny', 'Ostatnie sztuki', 'Na zamówienie']) }),
    P({ id: 'lait33', brand: 'Callebaut', cat: 'milk', color: '#8a5a34', name: 'Mleczna 33%', cacao: '33 %', origin: 'Mieszanka', fluidity: 4,
      blurb: 'Kremowa i mleczna, wysoka płynność. Oblewanie, dekoracje i formowanie — do wszystkiego.',
      ingredients: 'Cukier, mleko pełne w proszku, tłuszcz kakaowy, miazga kakaowa, emulgator, wanilia.',
      variants: makeVariants(24.0, ['Dostępny', 'Dostępny', 'Dostępny']) }),
    P({ id: 'laitcar', brand: 'Cacao Barry', cat: 'special', color: '#a9702f', name: 'Mleczna Karmel', cacao: '31 %', origin: 'Mieszanka', fluidity: 3,
      blurb: 'Nuty masła karmelowego i gotowanego mleka. Specjalność, która sprzedaje witrynę.',
      ingredients: 'Cukier, mleko pełne w proszku, tłuszcz kakaowy, mleko karmelizowane w proszku, sól, emulgator.',
      variants: makeVariants(27.0, ['Dostępny', 'Dostępny', 'Ostatnie sztuki']) }),
    P({ id: 'blanc29', brand: 'Callebaut', cat: 'white', color: '#c9a86a', name: 'Biała 29%', cacao: '29 %', origin: '—', fluidity: 4,
      blurb: 'Biała z wanilią, wysoka płynność. Idealna baza do barwienia i jasnych oblewów.',
      ingredients: 'Cukier, tłuszcz kakaowy, mleko pełne w proszku, emulgator (lecytyna), wanilia.',
      variants: makeVariants(25.0, ['Dostępny', 'Dostępny', 'Dostępny']) }),
    P({ id: 'ruby', brand: 'Callebaut', cat: 'special', color: '#b56576', name: 'Ruby RB1', cacao: '47,3 %', origin: 'Ziarno ruby', fluidity: 3,
      blurb: 'Naturalnie różowa kuwertura ruby, kwaskowe nuty czerwonych owoców. Gwarantowany efekt.',
      ingredients: 'Cukier, mleko pełne w proszku, tłuszcz kakaowy, miazga kakaowa, kwas cytrynowy, aromat.',
      variants: makeVariants(34.0, ['Dostępny', 'Ostatnie sztuki', 'Na zamówienie']) }),
    P({ id: 'lait38', brand: 'Cacao Barry', cat: 'milk', color: '#7a4d2c', name: 'Mleczna 38% — Intensywna', cacao: '38 %', origin: 'Mieszanka', fluidity: 3,
      blurb: 'Mleczna z większą ilością kakao, mniej słodka — profil pro. Świetna w ganache montée.',
      ingredients: 'Cukier, mleko pełne w proszku, miazga kakaowa, tłuszcz kakaowy, emulgator, wanilia.',
      variants: makeVariants(26.5, ['Dostępny', 'Dostępny', 'Dostępny']) }),
  ],
  bundles: [
    { id: 'labo', name: 'Pakiet Pracownia', color: '#4a2c1a', discount: 10,
      desc: '1 karton Ciemna 70% (20 kg) + 1 worek Mleczna 33% (10 kg). Niezbędnik pracowni, −10%.',
      items: [ { pid: 'noir70', brand: 'Callebaut', vkey: 'c20' }, { pid: 'lait33', brand: 'Callebaut', vkey: 's10' } ] },
    { id: 'decouverte', name: 'Pakiet Degustacyjny', color: '#8a5a34', discount: 15,
      desc: '3 worki 2,5 kg: Ciemna 54%, Mleczna 33%, Biała 29%. Aby poznać gamę, −15%.',
      items: [ { pid: 'noir54', brand: 'Callebaut', vkey: 's25' }, { pid: 'lait33', brand: 'Callebaut', vkey: 's25' }, { pid: 'blanc29', brand: 'Callebaut', vkey: 's25' } ] },
    { id: 'signature', name: 'Pakiet Signature', color: '#b56576', discount: 12,
      desc: '1 worek Ruby 10 kg + 1 worek Mleczna Karmel 10 kg. Witryny, które się wyróżniają, −12%.',
      items: [ { pid: 'ruby', brand: 'Callebaut', vkey: 's10' }, { pid: 'laitcar', brand: 'Cacao Barry', vkey: 's10' } ] },
  ],
  // références complémentaires suggérées (produit → produits)
  crossSell: {
    noir70: ['lait33', 'blanc29'], noir54: ['lait33', 'ruby'], noir80: ['blanc29', 'lait38'],
    lait33: ['noir70', 'laitcar'], laitcar: ['blanc29', 'ruby'], blanc29: ['ruby', 'noir70'],
    ruby: ['blanc29', 'laitcar'], lait38: ['noir54', 'blanc29'],
  },
};

// ---- helpers ----
window.MSlib = {
  prod: (id) => window.MS.products.find((p) => p.id === id),
  fmt: (cur, n) => {
    const c = window.MS.currencies[cur];
    const s = Number(n).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return c.suffix ? `${s} ${c.sym}` : `${c.sym}${s}`;
  },
  brutto: (netto, vat) => Math.round(netto * (1 + vat) * 100) / 100,
  bundlePrice: (bundle, cur) => {
    const full = bundle.items.reduce((s, it) => {
      const p = window.MSlib.prod(it.pid); const v = p.variants.find((x) => x.key === it.vkey);
      return s + v.netto[cur];
    }, 0);
    return { full: Math.round(full * 100) / 100, net: Math.round(full * (1 - bundle.discount / 100) * 100) / 100 };
  },
};
