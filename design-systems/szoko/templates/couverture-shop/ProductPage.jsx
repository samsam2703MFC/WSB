const { Button, IconButton, Icon, Tag, QuantityStepper, SectionHeading } = window.MisterSzokoDesignSystem_613e75;
const { fmt, brutto } = window.MSlib;

function ProductPage({ product: p, cur, onBack, onAdd, onOpen }) {
  const [vkey, setVkey] = React.useState(p.variants[0].key);
  const [qty, setQty] = React.useState(1);
  const [phone, setPhone] = React.useState('');
  const [smsOk, setSmsOk] = React.useState(false);
  const v = p.variants.find((x) => x.key === vkey);
  const cross = (window.MS.crossSell[p.id] || []).map(window.MSlib.prod).filter(Boolean);
  const tiers = window.MS.config.volumeTiers;

  return (
    <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-6)' }}>
      <button onClick={onBack} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', fontFamily: 'var(--font-sans)', fontSize: 14, fontWeight: 600, marginBottom: 'var(--space-5)' }}>
        <Icon name="chevronRight" size={16} style={{ transform: 'rotate(180deg)' }} /> Wróć do katalogu
      </button>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 'var(--space-7)', alignItems: 'start' }}>
        {/* gallery */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <div style={{ aspectRatio: '1/1', borderRadius: 'var(--radius-xl)', background: `radial-gradient(120% 120% at 30% 20%, ${p.color}, var(--choco-900))`, position: 'relative', boxShadow: 'var(--shadow-md)', display: 'flex', alignItems: 'flex-end', padding: 18 }}>
            <span style={{ position: 'absolute', top: 16, left: 16, fontFamily: 'var(--font-mono)', fontSize: 11, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'rgba(255,255,255,0.5)' }}>Zdjęcie produktu</span>
            <span style={{ background: 'rgba(255,255,255,0.92)', borderRadius: 'var(--radius-pill)', padding: '6px 14px', fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 600, color: 'var(--choco-800)' }}>Pastylki · kakao {p.cacao}</span>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 10 }}>
            {[0, 1, 2, 3].map((i) => <div key={i} style={{ aspectRatio: '1/1', borderRadius: 'var(--radius-md)', background: `radial-gradient(120% 120% at 30% 20%, ${p.color}, var(--choco-900))`, opacity: i === 0 ? 1 : 0.6, boxShadow: i === 0 ? 'inset 0 0 0 2px var(--brand)' : 'none', cursor: 'pointer' }} />)}
          </div>
        </div>

        {/* buy box */}
        <div>
          <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
            {p.origin !== '—' && <Tag tone="origin" icon={<Icon name="leaf" size={12} />}>{p.origin}</Tag>}
            <Tag tone="accent">Kakao {p.cacao}</Tag>
          </div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 600, letterSpacing: '0.1em', textTransform: 'uppercase', color: 'var(--caramel-600)', marginBottom: 6 }}>{p.brand}</div>
          <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-4xl)', lineHeight: 1.05, margin: '0 0 12px', color: 'var(--text-strong)', fontWeight: 600 }}>{p.name}</h1>
          <p style={{ fontSize: 'var(--text-lg)', lineHeight: 1.6, color: 'var(--text-body)', margin: '0 0 var(--space-5)', maxWidth: '48ch' }}>{p.blurb}</p>

          {/* conditionnement selector */}
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'var(--text-muted)', marginBottom: 10 }}>Opakowanie</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 'var(--space-5)' }}>
            {p.variants.map((va) => {
              const on = va.key === vkey; const oos = va.stock === 'Sur commande';
              return (
                <button key={va.key} onClick={() => setVkey(va.key)} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 14, textAlign: 'left', cursor: 'pointer', padding: '14px 18px', borderRadius: 'var(--radius-md)', border: 'none', background: on ? 'var(--brand-quiet)' : 'var(--surface-card)', boxShadow: on ? 'inset 0 0 0 2px var(--brand)' : 'inset 0 0 0 1.5px var(--border-default)', transition: 'all var(--dur-base) var(--ease-out)' }}>
                  <div>
                    <div style={{ fontFamily: 'var(--font-sans)', fontWeight: 700, fontSize: 15, color: 'var(--text-strong)' }}>{va.label}</div>
                    <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: va.qty > 20 ? 'var(--success)' : va.qty > 0 ? 'var(--caramel-600)' : 'var(--text-muted)', marginTop: 3 }}>{va.stock}{va.arrival ? ' · dostawa ' + va.arrival : ''}</div>
                  </div>
                  <div style={{ textAlign: 'right', fontFamily: 'var(--font-mono)' }}>
                    <div style={{ fontSize: 17, color: 'var(--price)', fontWeight: 500 }}>{fmt(cur, va.netto[cur])} <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>netto</span></div>
                    <div style={{ fontSize: 12, color: 'var(--caramel-600)', marginTop: 2 }}>{fmt(cur, va.perKg[cur])}/kg</div>
                  </div>
                </button>
              );
            })}
          </div>

          {/* volume tiers */}
          <div style={{ background: 'var(--surface-raised)', borderRadius: 'var(--radius-md)', padding: '12px 16px', marginBottom: 'var(--space-5)', display: 'flex', gap: 18, alignItems: 'center', flexWrap: 'wrap' }}>
            <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-strong)' }}>Rabat ilościowy (na referencję)</span>
            {tiers.map((t) => <span key={t.min} style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5, color: 'var(--text-body)' }}>{t.min}+ kartonów <b style={{ color: 'var(--berry-600)' }}>−{t.pct}%</b></span>)}
          </div>

          <div style={{ display: 'flex', gap: 14, alignItems: 'center', marginBottom: 16 }}>
            <QuantityStepper value={qty} onChange={setQty} />
            <div style={{ fontFamily: 'var(--font-mono)', fontSize: 13, color: 'var(--text-muted)' }}>Razem: <b style={{ color: 'var(--price)', fontSize: 16 }}>{fmt(cur, v.netto[cur] * qty)}</b> netto · {fmt(cur, brutto(v.netto[cur] * qty, p.vat))} brutto</div>
          </div>
          <Button variant="accent" size="lg" block disabled={v.qty === 0} iconLeft={<Icon name="bag" size={20} />} onClick={() => onAdd(p, v, qty)}>Dodaj do koszyka</Button>
          {v.qty === 0 && (
            <div style={{ marginTop: 14, border: '1px solid var(--border-default)', borderRadius: 8, padding: 16, background: 'var(--surface-card)' }}>
              {smsOk ? (
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', color: 'var(--success)', fontSize: 14, fontWeight: 600 }}><Icon name="check" size={18} /> Damy znać SMS-em, gdy format wróci do sprzedaży.</div>
              ) : (
                <>
                  <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-strong)', marginBottom: 4 }}>Chwilowo niedostępny — dostawa {v.arrival}</div>
                  <div style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 12 }}>Zostaw numer — trzymamy Cię na bieżąco SMS-em.</div>
                  <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                    <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+48 600 000 000" inputMode="tel" style={{ flex: 1, minWidth: 160, border: '1px solid var(--border-default)', borderRadius: 6, padding: '11px 12px', fontFamily: 'var(--font-sans)', fontSize: 14, outline: 'none', background: 'var(--bg-page)' }} />
                    <Button variant="secondary" size="sm" disabled={phone.length < 9} onClick={() => setSmsOk(true)}>Powiadom mnie SMS-em</Button>
                  </div>
                </>
              )}
            </div>
          )}

          {/* tech specs */}
          <div style={{ marginTop: 'var(--space-6)', borderTop: '1px solid var(--border-subtle)', paddingTop: 'var(--space-5)' }}>
            {[['Marka', p.brand], ['Kakao', p.cacao], ['Pochodzenie', p.origin], ['Płynność', null], ['VAT', `${Math.round(p.vat*100)}%`]].map(([k, val]) => (
              <div key={k} style={{ display: 'flex', justifyContent: 'space-between', padding: '9px 0', borderBottom: '1px solid var(--border-subtle)', fontSize: 14 }}>
                <span style={{ color: 'var(--text-muted)' }}>{k}</span>
                {val !== null ? <span style={{ fontWeight: 600, color: 'var(--text-strong)' }}>{val}</span> : <window.Fluidity value={p.fluidity} />}
              </div>
            ))}
            <div style={{ padding: '12px 0', fontSize: 13.5, lineHeight: 1.55, color: 'var(--text-body)' }}><b>Składniki.</b> {p.ingredients}</div>
            <div style={{ fontSize: 13.5, lineHeight: 1.55, color: 'var(--text-body)' }}><b>Alergeny.</b> {p.allergens}</div>
          </div>
        </div>
      </div>

      {/* cross-sell */}
      <section style={{ marginTop: 'var(--space-9)' }}>
        <SectionHeading eyebrow="Często zamawiane razem" title="Produkty uzupełniające" style={{ marginBottom: 'var(--space-6)' }} />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(230px, 1fr))', gap: 'var(--space-4)' }}>
          {cross.map((cp) => <window.ProCard key={cp.id} p={cp} cur={cur} onOpen={onOpen} onAdd={(pp, vv) => onAdd(pp, vv, 1)} />)}
        </div>
      </section>
    </div>
  );
}

Object.assign(window, { ProductPage });
