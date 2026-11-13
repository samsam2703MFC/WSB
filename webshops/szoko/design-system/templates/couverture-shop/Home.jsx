const { Button, SectionHeading, Icon } = window.MisterSzokoDesignSystem_613e75;
const { fmt } = window.MSlib;

function Hero({ onShop, onPro }) {
  return (
    <section style={{ background: 'var(--choco-900)', color: 'var(--cream-50)' }}>
      <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-10) var(--space-6)' }}>
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--caramel-400)', marginBottom: 20 }}>Kuwertura · Pastylki</div>
        <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 'clamp(30px, 5vw, 46px)', lineHeight: 1.08, margin: '0 0 18px', fontWeight: 600, letterSpacing: '-0.01em', maxWidth: 620 }}>Kuwertura dla profesjonalistów. Najlepsza cena za kilogram.</h1>
        <p style={{ fontSize: 16, lineHeight: 1.6, color: 'var(--cream-200)', maxWidth: 480, margin: '0 0 28px' }}>Ciemna, mleczna, biała, ruby — 2,5 / 10 / 20 kg. Ceny netto.</p>
        <div style={{ display: 'flex', gap: 22, alignItems: 'center', flexWrap: 'wrap' }}>
          <Button variant="accent" onClick={onShop}>Zobacz katalog</Button>
          <a style={{ color: 'var(--cream-200)', fontSize: 14.5, fontWeight: 600, cursor: 'pointer', borderBottom: '1px solid var(--choco-500)', paddingBottom: 2 }} onClick={onPro}>Konto pro od 40 kg/mies. →</a>
        </div>
      </div>
      <div style={{ borderTop: '1px solid var(--choco-700)' }}>
        <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: '16px var(--space-6)', display: 'flex', gap: 36, flexWrap: 'wrap', fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--choco-300)', letterSpacing: '0.04em' }}>
          <span>Dostawa w Polsce w 48 h</span>
          <span>Im większe opakowanie, tym tańszy kilogram</span>
          <span>Faktury netto/VAT + KSeF automatycznie</span>
        </div>
      </div>
    </section>
  );
}

function Home({ cur, query, onOpen, onAdd, onOpenBundle, onPro }) {
  const [cat, setCat] = React.useState('all');
  const q = (query || '').toLowerCase();
  const list = window.MS.products.filter((p) => (cat === 'all' || p.cat === cat) && (!q || (p.name + p.origin + p.cacao).toLowerCase().includes(q)));
  return (
    <div>
      <Hero onShop={() => document.getElementById('cat').scrollIntoView({ behavior: 'smooth' })} onPro={onPro} />
      <section style={{ borderBottom: '1px solid var(--border-subtle)', background: 'var(--surface-card)' }}>
        <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-7) var(--space-6)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 20, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontFamily: 'var(--font-display)', fontSize: 24, color: 'var(--text-strong)' }}>Kupujesz ponad 40 kg miesięcznie?</div>
            <div style={{ fontSize: 14.5, color: 'var(--text-muted)', marginTop: 4 }}>Załóż konto pro — rabaty lojalnościowe i zakup w 4 kliknięciach.</div>
          </div>
          <Button variant="primary" onClick={onPro} iconRight={<Icon name="arrowRight" size={17} />}>Otwórz konto pro</Button>
        </div>
      </section>
      <section id="cat" style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-8) var(--space-6) var(--space-9)' }}>
        <SectionHeading eyebrow="Katalog" title="Czekolada kuwertura" style={{ marginBottom: 'var(--space-5)' }} />
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 'var(--space-6)', borderBottom: '1px solid var(--border-subtle)', paddingBottom: 18 }}>
          {window.MS.categories.map((c) => {
            const on = c.key === cat;
            return <button key={c.key} onClick={() => setCat(c.key)} style={{ fontSize: 13.5, fontWeight: 600, cursor: 'pointer', padding: '8px 15px', borderRadius: 6, border: on ? '1px solid var(--choco-800)' : '1px solid var(--border-default)', background: on ? 'var(--choco-800)' : 'transparent', color: on ? 'var(--cream-50)' : 'var(--text-body)', transition: 'all var(--dur-base) var(--ease-out)' }}>{c.label}</button>;
          })}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(230px, 1fr))', gap: 'var(--space-4)' }}>
          {list.map((p) => <window.ProCard key={p.id} p={p} cur={cur} onOpen={onOpen} onAdd={onAdd} />)}
        </div>
        {list.length === 0 && <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: 'var(--space-8)' }}>Brak wyników dla „{query}”.</p>}
      </section>

    </div>
  );
}

Object.assign(window, { Home });
