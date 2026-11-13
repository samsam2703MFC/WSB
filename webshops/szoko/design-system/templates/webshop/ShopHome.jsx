const { ProductCard, Button, SectionHeading, Tag, Icon, RatingStars } = window.MisterSzokoDesignSystem_613e75;

function Hero({ onShop }) {
  return (
    <section style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-8) var(--space-6) var(--space-6)' }}>
      <div style={{
        position: 'relative', borderRadius: 'var(--radius-2xl)', overflow: 'hidden',
        background: 'radial-gradient(130% 120% at 15% 10%, var(--choco-600), var(--choco-900))',
        color: 'var(--cream-50)', padding: 'var(--space-10) var(--space-9)',
        minHeight: 420, display: 'flex', flexDirection: 'column', justifyContent: 'center',
        boxShadow: 'var(--shadow-lg)',
      }}>
        <img src="../../assets/logo.png" alt="" aria-hidden="true" style={{
          position: 'absolute', right: -40, bottom: -50, height: 440, opacity: 0.10,
          filter: 'brightness(0) invert(1)', pointerEvents: 'none',
        }} />
        <div style={{ position: 'relative', maxWidth: 620 }}>
          <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--caramel-400)' }}>Bean to bar · Small batch</span>
          <h1 style={{
            fontFamily: 'var(--font-display)', fontSize: 'var(--text-6xl)', lineHeight: 1.02,
            margin: '18px 0 20px', fontWeight: 400, letterSpacing: '-0.02em',
          }}>Chocolate, the way <span style={{ fontStyle: 'italic', color: 'var(--caramel-400)' }}>Mister&nbsp;Szoko</span> intended.</h1>
          <p style={{ fontSize: 'var(--text-lg)', lineHeight: 1.55, color: 'var(--cream-200)', maxWidth: 480, margin: '0 0 30px' }}>
            Single-origin bars and hand-piped pralines, tempered by hand in small batches. Packed cold, shipped fast.
          </p>
          <div style={{ display: 'flex', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
            <Button variant="accent" size="lg" onClick={onShop} iconRight={<Icon name="arrowRight" size={18} />}>Shop the collection</Button>
            <Button variant="secondary" size="lg" style={{ color: 'var(--cream-50)', boxShadow: 'inset 0 0 0 1.5px rgba(255,255,255,0.4)' }}>Build your box</Button>
          </div>
        </div>
      </div>
    </section>
  );
}

function Filters({ items, active, onPick }) {
  return (
    <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap', marginBottom: 'var(--space-6)' }}>
      {items.map((c) => {
        const on = c === active;
        return (
          <button key={c} onClick={() => onPick(c)} style={{
            fontFamily: 'var(--font-sans)', fontSize: 14, fontWeight: 600, cursor: 'pointer',
            padding: '9px 18px', borderRadius: 'var(--radius-pill)', border: 'none',
            background: on ? 'var(--brand)' : 'var(--surface-card)',
            color: on ? 'var(--text-inverse)' : 'var(--text-body)',
            boxShadow: on ? 'var(--shadow-sm)' : 'inset 0 0 0 1.5px var(--border-default)',
            transition: 'all var(--dur-base) var(--ease-out)',
          }}>{c}</button>
        );
      })}
    </div>
  );
}

function StoryStrip() {
  const items = [
    { icon: 'leaf', t: 'Single origin', d: 'Traceable beans from named estates.' },
    { icon: 'gift', t: 'Made to gift', d: 'Hand-tied boxes with a note from you.' },
    { icon: 'truck', t: 'Packed cold', d: 'Insulated shipping so nothing melts.' },
  ];
  return (
    <section style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: '0 var(--space-6) var(--space-8)' }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 'var(--space-5)' }}>
        {items.map((i) => (
          <div key={i.t} style={{ display: 'flex', gap: 'var(--space-4)', alignItems: 'flex-start', background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-5)', boxShadow: 'var(--shadow-xs)' }}>
            <div style={{ width: 46, height: 46, borderRadius: 'var(--radius-pill)', background: 'var(--brand-quiet)', color: 'var(--brand)', display: 'flex', alignItems: 'center', justifyContent: 'center', flex: 'none' }}>
              <Icon name={i.icon} size={22} />
            </div>
            <div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 19, color: 'var(--text-strong)' }}>{i.t}</div>
              <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 14, lineHeight: 1.45 }}>{i.d}</p>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

function ShopHome({ onOpenProduct, onAdd }) {
  const D = window.MS_DATA;
  const [filter, setFilter] = React.useState('All');
  return (
    <div>
      <Hero onShop={() => document.getElementById('grid').scrollIntoView({ behavior: 'smooth' })} />
      <StoryStrip />
      <section id="grid" style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: '0 var(--space-6) var(--space-9)' }}>
        <SectionHeading eyebrow="Our collection" title="Bars worth slowing down for"
          lead="Tempered by hand in small batches. Every bar names its origin and cocoa percentage." style={{ marginBottom: 'var(--space-6)' }} />
        <Filters items={D.collections} active={filter} onPick={setFilter} />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 'var(--space-5)' }}>
          {D.products.map((p) => (
            <ProductCard key={p.id} name={p.name} origin={p.origin} cocoa={p.cocoa}
              price={p.price} was={p.was} badge={p.badge} rating={p.rating} count={p.count}
              onAdd={() => onAdd(p)} onClick={() => onOpenProduct(p)} style={{ cursor: 'pointer' }} />
          ))}
        </div>
      </section>

      {/* editorial chocolate panel */}
      <section style={{ maxWidth: 'var(--container)', margin: '0 auto var(--space-9)', padding: '0 var(--space-6)' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 0, borderRadius: 'var(--radius-2xl)', overflow: 'hidden', boxShadow: 'var(--shadow-md)' }}>
          <div style={{ background: 'radial-gradient(120% 120% at 70% 20%, var(--choco-500), var(--choco-800))', minHeight: 340, position: 'relative' }}>
            <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--choco-200)', fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', opacity: 0.6 }}>Atelier photo</div>
          </div>
          <div style={{ background: 'var(--surface-card)', padding: 'var(--space-9)', display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
            <Tag tone="accent" style={{ alignSelf: 'flex-start', marginBottom: 16 }}>The atelier</Tag>
            <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-4xl)', lineHeight: 1.05, margin: '0 0 16px', color: 'var(--text-strong)', fontWeight: 400 }}>Slow chocolate, made by hand</h2>
            <p style={{ fontSize: 'var(--text-lg)', lineHeight: 1.6, color: 'var(--text-muted)', margin: '0 0 26px' }}>We roast, conch and temper in tiny batches so each bar keeps its origin's character — a clean snap and a long finish.</p>
            <Button variant="primary" style={{ alignSelf: 'flex-start' }} iconRight={<Icon name="arrowRight" size={18} />}>Read our story</Button>
          </div>
        </div>
      </section>
    </div>
  );
}

Object.assign(window, { ShopHome });
