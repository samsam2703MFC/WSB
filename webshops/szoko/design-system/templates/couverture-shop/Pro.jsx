const { Button, Icon, Input, Tag, QuantityStepper } = window.MisterSzokoDesignSystem_613e75;
const { fmt, brutto, prod } = window.MSlib;

const TIERS_PLN = [ { min: 50000, pct: 15 }, { min: 25000, pct: 12 }, { min: 10000, pct: 8 }, { min: 5000, pct: 5 } ];
const tierFor = (totalPLN) => (TIERS_PLN.find((t) => totalPLN >= t.min) || { pct: 0 }).pct;

// --- Formulaire remise : CA des 3 derniers mois ---
function RemiseForm({ cur, remise, onApply }) {
  const months = ['Kwiecień 2026', 'Maj 2026', 'Czerwiec 2026'];
  const [vals, setVals] = React.useState(['', '', '']);
  const [applied, setApplied] = React.useState(remise > 0);
  const rate = cur === 'EUR' ? 4.3 : 1;
  const total = vals.reduce((s, v) => s + (parseFloat(v) || 0), 0);
  const pct = tierFor(total * rate);
  return (
    <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-sm)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, marginBottom: 6 }}>
        <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 24, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Twój rabat lojalnościowy</h2>
        {applied && <Tag tone="berry">Aktywny rabat: −{remise}%</Tag>}
      </div>
      <p style={{ margin: '0 0 18px', fontSize: 14, color: 'var(--text-muted)', lineHeight: 1.5 }}>Podaj swój obrót czekoladą z ostatnich 3 miesięcy — Twój rabat procentowy zostanie od razu zastosowany do wszystkich zamówień.</p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12, marginBottom: 14 }}>
        {months.map((m, i) => (
          <Input key={m} label={m} placeholder={'0 ' + (cur === 'PLN' ? 'zł' : '€')} inputMode="numeric" value={vals[i]}
            onChange={(e) => { const n = [...vals]; n[i] = e.target.value.replace(/[^\d.,]/g, '').replace(',', '.'); setVals(n); setApplied(false); }} />
        ))}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 14, color: 'var(--text-body)' }}>Łączny obrót: <b style={{ color: 'var(--price)' }}>{fmt(cur, total)}</b> → rabat <b style={{ color: pct > 0 ? 'var(--berry-600)' : 'var(--text-muted)', fontSize: 18 }}>−{pct} %</b></div>
        <Button variant="accent" size="sm" disabled={total <= 0} onClick={() => { onApply(pct); setApplied(true); }} iconLeft={<Icon name="check" size={16} />}>Zastosuj rabat</Button>
      </div>
      <div style={{ marginTop: 14, display: 'flex', gap: 16, flexWrap: 'wrap', fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>
        {TIERS_PLN.map((t) => <span key={t.min}>≥ {fmt('PLN', t.min).replace(',00', '')} → <b style={{ color: 'var(--caramel-600)' }}>−{t.pct}%</b></span>)}
      </div>
    </div>
  );
}

// --- Commande express : 4 clics max ---
function ProSpace({ cur, remise, setRemise }) {
  const [lines, setLines] = React.useState({});
  const [done, setDone] = React.useState(null);
  const items = Object.values(lines);
  const add = (p, v) => { const k = p.id + '_' + v.key; setLines((c) => ({ ...c, [k]: { key: k, pid: p.id, v, qty: (c[k]?.qty || 0) + 1 } })); setDone(null); };
  const setQty = (k, n) => setLines((c) => ({ ...c, [k]: { ...c[k], qty: n } }));
  const remove = (k) => setLines((c) => { const n = { ...c }; delete n[k]; return n; });
  const netto = items.reduce((s, l) => s + l.v.netto[cur] * l.qty, 0);
  const rabat = Math.round(netto * (remise / 100) * 100) / 100;
  const nettoR = netto - rabat;
  const vat = items.reduce((s, l) => s + (l.v.netto[cur] * l.qty * (1 - remise / 100)) * prod(l.pid).vat, 0);
  const ship = nettoR >= window.MS.config.freeShip[cur] || nettoR === 0 ? 0 : window.MS.config.shipping[cur];
  const total = nettoR + vat + ship;

  return (
    <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-6)' }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 14, flexWrap: 'wrap', marginBottom: 6 }}>
        <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-3xl)', margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Strefa pro — Cukiernia Demo</h1>
        <Tag tone="accent" icon={<Icon name="check" size={12} />}>Zakup w maks. 4 kliknięciach</Tag>
        <Tag tone="origin">Dostęp pro: 40 kg+ / mies.</Tag>
      </div>
      <p style={{ margin: '0 0 var(--space-6)', color: 'var(--text-muted)', fontSize: 15 }}>Adres i płatność zapisane — kliknij opakowanie, potem „Zapłać”. To wszystko. W tym miesiącu kupiłeś już <b style={{ color: 'var(--text-strong)' }}>62 kg</b> — dostęp pro utrzymany (próg 40 kg).</p>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 'var(--space-6)', alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          <RemiseForm cur={cur} remise={remise} onApply={setRemise} />

          {/* commande rapide */}
          <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-sm)' }}>
            <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 24, margin: '0 0 4px', color: 'var(--text-strong)', fontWeight: 600 }}>Szybkie zamówienie</h2>
            <p style={{ margin: '0 0 16px', fontSize: 14, color: 'var(--text-muted)' }}>1 klik = 1 opakowanie dodane do zamówienia.</p>
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              {window.MS.products.map((p) => (
                <div key={p.id} style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 14, padding: '12px 0', borderTop: '1px solid var(--border-subtle)' }}>
                  <span style={{ width: 40, height: 40, flex: 'none', borderRadius: 10, background: `radial-gradient(120% 120% at 30% 20%, ${p.color}, var(--choco-900))` }} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, fontSize: 14.5, color: 'var(--text-strong)' }}>{p.name}</div>
                    <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11.5, color: 'var(--text-muted)' }}>{p.brand} · kakao {p.cacao}</div>
                  </div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    {p.variants.map((v) => (
                      <button key={v.key} onClick={() => add(p, v)} style={{ border: 'none', cursor: 'pointer', borderRadius: 'var(--radius-pill)', padding: '8px 13px', background: 'var(--surface-raised)', color: 'var(--brand)', fontFamily: 'var(--font-mono)', fontSize: 12, fontWeight: 600, transition: 'all var(--dur-fast) var(--ease-out)' }}
                        onMouseEnter={(e) => { e.currentTarget.style.background = 'var(--brand)'; e.currentTarget.style.color = '#fff'; }}
                        onMouseLeave={(e) => { e.currentTarget.style.background = 'var(--surface-raised)'; e.currentTarget.style.color = 'var(--brand)'; }}
                        title={fmt(cur, v.netto[cur]) + ' netto'}>+ {v.kg} kg</button>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* bon de commande express */}
        <div style={{ background: 'var(--choco-900)', color: 'var(--cream-100)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-lg)', position: 'sticky', top: 100 }}>
          {done ? (
            <div style={{ textAlign: 'center', padding: '18px 0' }}>
              <div style={{ width: 60, height: 60, borderRadius: 999, background: 'var(--success)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px' }}><Icon name="check" size={30} /></div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 24, marginBottom: 8 }}>Zamówienie opłacone!</div>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 13, color: 'var(--choco-200)', marginBottom: 18 }}>Faktura {done} · BLIK · KSeF ✓ · dostawa 48 h</div>
              <Button variant="accent" size="sm" onClick={() => setDone(null)}>Nowe zamówienie</Button>
            </div>
          ) : (
            <>
              <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: '0 0 14px', fontWeight: 600 }}>Zamówienie</h2>
              {items.length === 0 && <p style={{ fontSize: 14, color: 'var(--choco-200)', margin: '0 0 14px' }}>Kliknij opakowanie po lewej, aby zacząć.</p>}
              {items.map((l) => (
                <div key={l.key} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 0', borderBottom: '1px solid rgba(255,255,255,0.12)' }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13.5, fontWeight: 600 }}>{prod(l.pid).name}</div>
                    <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--choco-300)' }}>{l.v.label}</div>
                  </div>
                  <QuantityStepper size="sm" value={l.qty} onChange={(n) => setQty(l.key, n)} style={{ background: 'transparent', boxShadow: 'inset 0 0 0 1.5px rgba(255,255,255,0.25)' }} />
                  <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, minWidth: 74, textAlign: 'right' }}>{fmt(cur, l.v.netto[cur] * l.qty)}</span>
                  <button onClick={() => remove(l.key)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--choco-300)' }}><Icon name="x" size={14} /></button>
                </div>
              ))}
              <div style={{ margin: '14px 0', display: 'flex', flexDirection: 'column', gap: 6, fontFamily: 'var(--font-mono)', fontSize: 13 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--choco-200)' }}>Suma netto</span><span>{fmt(cur, netto)}</span></div>
                {remise > 0 && <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--gold-500)' }}><span>Rabat lojalnościowy −{remise}%</span><span>−{fmt(cur, rabat)}</span></div>}
                <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--choco-200)' }}>VAT 23%</span><span>{fmt(cur, vat)}</span></div>
                <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--choco-200)' }}>Dostawa</span><span>{ship === 0 ? 'Gratis' : fmt(cur, ship)}</span></div>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', borderTop: '1.5px solid rgba(255,255,255,0.2)', padding: '12px 0 16px' }}>
                <b>Razem brutto</b><span style={{ fontFamily: 'var(--font-mono)', fontSize: 24, fontWeight: 600, color: 'var(--gold-500)' }}>{fmt(cur, total)}</span>
              </div>
              <div style={{ fontSize: 12, color: 'var(--choco-300)', marginBottom: 12, lineHeight: 1.5 }}>
                <Icon name="truck" size={13} style={{ verticalAlign: '-2px' }} /> ul. Przykładowa 12, Warszawa · <b style={{ color: 'var(--cream-100)' }}>BLIK</b> zapisany · faktura + KSeF automatycznie
              </div>
              <Button variant="accent" size="lg" block disabled={items.length === 0} iconRight={<Icon name="arrowRight" size={18} />}
                onClick={() => { setDone('FV/2026/000' + (43 + Math.floor(Math.random() * 50))); setLines({}); }}>Zapłać {items.length > 0 ? fmt(cur, total) : ''}</Button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { ProSpace });
