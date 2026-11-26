const { chromium } = require('playwright');
const OUT = process.argv[2], B = 'http://127.0.0.1:8095/';
const SEK = (no, tytul, okres, tekst, f1, f2, odwr, sklep) => `
<section style="max-width:1240px;margin:0 auto;padding:48px 28px;display:flex;flex-wrap:${odwr?'wrap-reverse':'wrap'};align-items:center;gap:clamp(32px,5vw,80px);border-top:1px solid #d8cdbd;">
 ${odwr?'':`<div style="flex:1 1 340px;min-width:0;display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div style="position:relative;aspect-ratio:3/4;overflow:hidden;background:#ebe3d6"><img src="assets/photos/ola/${f1}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"></div><div style="position:relative;aspect-ratio:3/4;overflow:hidden;background:#ebe3d6"><img src="assets/photos/ola/${f2}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"></div></div>`}
 <div style="flex:1 1 340px;min-width:0;display:flex;flex-direction:column;gap:20px;">
  <span style="font-family:var(--font-display);font-style:italic;font-size:1.6rem;color:#8a7d6c;">${no}</span>
  <h2 style="margin:0;font-family:var(--font-display);font-weight:400;font-size:clamp(2rem,3.4vw,2.8rem);line-height:1.1;">${tytul}</h2>
  <span style="font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#465802;">${okres}</span>
  <p style="margin:0;max-width:52ch;font-size:17px;line-height:1.85;color:#4a423a;">${tekst}</p>
  <a href="#" style="align-self:flex-start;display:inline-flex;gap:14px;align-items:center;border:1px solid #2e2924;padding:14px 24px;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#2e2924;">${sklep} <span>→</span></a>
 </div>
 ${odwr?`<div style="flex:1 1 340px;min-width:0;display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div style="position:relative;aspect-ratio:3/4;overflow:hidden;background:#ebe3d6"><img src="assets/photos/ola/${f1}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"></div><div style="position:relative;aspect-ratio:3/4;overflow:hidden;background:#ebe3d6"><img src="assets/photos/ola/${f2}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"></div></div>`:''}
</section>`;
const A = SEK('06','Dekoracje świąteczne','Zamówienia · 15 listopada – 20 grudnia','Wieńce adwentowe, stroiki na stół i girlandy, robione ręcznie z jodły, suszonych kwiatów i sezonowych gałązek. Także dekoracje świąteczne dla firm: recepcje, witryny, wnętrza biur.','firmy-recepcja-ostrokrzew.jpg','stol-bursztyn.jpg',true,'Zamów w sklepie')
  + SEK('07','Wianki','Zamówienia · 1 – 20 grudnia','Wianki na drzwi i do zawieszenia — z eukaliptusa, jodły, suszonych hortensji i gipsówki. Każdy robiony pojedynczo, w kilku rozmiarach.','firmy-hortensje-instalacja.jpg','gipsowka-roze-zblizenie.jpg',false,'Zobacz wianki w sklepie');
const KB = (f, t, o) => `<a href="#" style="display:flex;flex-direction:column;gap:14px;color:#f4efe7"><div style="position:relative;aspect-ratio:4/3;overflow:hidden"><img src="assets/photos/ola/${f}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"></div><div style="display:flex;justify-content:space-between;align-items:baseline;gap:16px"><span style="font-family:var(--font-display);font-size:1.8rem;font-weight:300">${t}</span><span style="font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#c9b99f;white-space:nowrap">${o}</span></div><span style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;border-bottom:1px solid #c9b99f;align-self:flex-start;padding-bottom:4px">w sklepie →</span></a>`;
const Bv = `<section style="background:#2e2924;color:#ebe3d6;"><div style="max-width:1240px;margin:0 auto;padding:clamp(56px,7vw,96px) 28px;display:flex;flex-direction:column;gap:40px;">
 <div style="display:flex;flex-wrap:wrap;gap:24px 64px;align-items:flex-end"><div style="flex:1 1 320px"><span style="font-family:var(--font-display);font-style:italic;font-size:1.5rem;color:#c9b99f">sezonowo</span><h2 style="margin:8px 0 0;font-family:var(--font-display);font-weight:300;font-size:clamp(2.2rem,4vw,3.2rem);line-height:1.08;color:#f4efe7">Do zamówienia w sklepie</h2></div>
 <p style="flex:1.2 1 380px;margin:0;color:#d8cdbd;max-width:56ch">Dekoracje świąteczne i wianki zamawiasz w sklepie internetowym — z odbiorem w pracowni lub dostawą. Każda sekcja pokazuje się na stronie tylko w swoim okresie.</p></div>
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:28px">${KB('firmy-recepcja-ostrokrzew.jpg','Dekoracje świąteczne','15 lis – 20 gru')}${KB('firmy-hortensje-instalacja.jpg','Wianki','1 – 20 gru')}</div>
</div></section>`;
(async () => {
  const b = await chromium.launch();
  for (const [nazwa, html] of [['oferta-A', A], ['oferta-B', Bv]]) {
    const p = await b.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    await p.goto(B + encodeURIComponent('Ola flora - wesela v2.dc.html') + '#oferta'); await p.waitForTimeout(3000);
    const box = await p.evaluate((html) => {
      const h = [...document.querySelectorAll('h2')].find(x => x.textContent.trim() === 'Kwiaty');
      const sek = h.closest('section');
      const w = document.createElement('div'); w.id = 'nowe'; w.innerHTML = html; sek.after(w);
      const r1 = sek.getBoundingClientRect(), r2 = w.getBoundingClientRect();
      return { y: r1.top + scrollY, h: r2.bottom - r1.top };
    }, html);
    await p.waitForTimeout(1500);
    await p.screenshot({ path: `${OUT}/${nazwa}.png`, fullPage: true, clip: { x: 0, y: box.y, width: 1440, height: box.h } });
    await p.close();
  }
  for (const s of ['sklep-start', 'sklep-produkt', 'sklep-koszyk']) {
    const p = await b.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    await p.goto(B + 'makiety/' + s + '.html'); await p.waitForTimeout(1200);
    await p.screenshot({ path: `${OUT}/${s}.png`, fullPage: true }); await p.close();
    const m = await b.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 });
    await m.goto(B + 'makiety/' + s + '.html'); await m.waitForTimeout(1200);
    await m.screenshot({ path: `${OUT}/${s}-telefon.png`, fullPage: true }); await m.close();
  }
  await b.close();
})();
