const { Icon, Tag, Card, Button } = window.MisterSzokoDesignSystem_613e75;
const { fmt, brutto } = window.MSlib;

// Płynność — minimal dots
function Fluidity({ value, size = 7 }) {
  return (
    <span style={{ display: 'inline-flex', gap: 4, alignItems: 'center' }} title={`Płynność ${value}/5`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} style={{ width: size, height: size, borderRadius: 999, background: i <= value ? 'var(--choco-600)' : 'var(--cream-300)' }} />
      ))}
    </span>
  );
}

// Netto prominent, brutto below
function PriceHT({ netto, vat, cur, size = 'md' }) {
  const big = size === 'lg' ? 32 : size === 'sm' ? 17 : 22;
  return (
    <div style={{ fontFamily: 'var(--font-mono)', lineHeight: 1.1 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 7 }}>
        <span style={{ fontSize: big, color: 'var(--price)', fontWeight: 500 }}>{fmt(cur, netto)}</span>
        <span style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.06em' }}>netto</span>
      </div>
      <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{fmt(cur, brutto(netto, vat))} brutto</div>
    </div>
  );
}

// Karta produktu — flat, minimal
function ProCard({ p, cur, onOpen, onAdd }) {
  const v0 = p.variants[0];
  const [hover, setHover] = React.useState(false);
  const cat = window.MS.categories.find((c) => c.key === p.cat);
  return (
    <div onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)} onClick={() => onOpen(p)}
      style={{ background: 'var(--surface-card)', borderRadius: 8, overflow: 'hidden', border: `1px solid ${hover ? 'var(--choco-400)' : 'var(--border-subtle)'}`, transition: 'border-color var(--dur-base) var(--ease-out)', display: 'flex', flexDirection: 'column', cursor: 'pointer' }}>
      <div style={{ aspectRatio: '5/3', background: p.color }} />
      <div style={{ padding: '18px 18px 16px', display: 'flex', flexDirection: 'column', gap: 8, flex: 1 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontFamily: 'var(--font-mono)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.09em', color: 'var(--text-muted)' }}>
          <span>{cat.label}{p.origin !== '—' && p.origin !== 'Mieszanka' ? ' · ' + p.origin : ''}</span>
          <Fluidity value={p.fluidity} />
        </div>
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, fontWeight: 600, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'var(--caramel-600)' }}>{p.brand}</div>
        <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 20, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>{p.name}</h3>
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>pastylki · kakao {p.cacao}</div>
        <div style={{ marginTop: 'auto', paddingTop: 10, borderTop: '1px solid var(--border-subtle)', display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 10 }}>
          <span style={{ fontFamily: 'var(--font-mono)', fontSize: 15, color: 'var(--price)', whiteSpace: 'nowrap' }}>od {fmt(cur, v0.netto[cur])} <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>netto</span></span>
          <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--caramel-600)', whiteSpace: 'nowrap' }}>{fmt(cur, p.variants[2].perKg[cur])}/kg</span>
        </div>
        <button onClick={(e) => { e.stopPropagation(); onAdd(p, p.variants[0]); }}
          style={{ marginTop: 6, width: '100%', border: '1px solid var(--choco-700)', background: hover ? 'var(--choco-700)' : 'transparent', color: hover ? 'var(--cream-50)' : 'var(--choco-700)', borderRadius: 6, padding: '10px 0', fontFamily: 'var(--font-sans)', fontSize: 13.5, fontWeight: 700, cursor: 'pointer', transition: 'all var(--dur-base) var(--ease-out)' }}>Do koszyka</button>
      </div>
    </div>
  );
}

Object.assign(window, { Fluidity, PriceHT, ProCard });
