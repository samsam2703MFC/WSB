const { Button, IconButton, Icon, Tag, PriceTag, RatingStars, QuantityStepper, Select, ProductCard, SectionHeading } = window.MisterSzokoDesignSystem_613e75;

function ShopProductPage({ product, onBack, onAdd, onOpenProduct }) {
  const [qty, setQty] = React.useState(1);
  const [wish, setWish] = React.useState(false);
  const D = window.MS_DATA;
  const related = D.products.filter((p) => p.id !== product.id).slice(0, 4);
  return (
    <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-6)' }}>
      <button onClick={onBack} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', fontFamily: 'var(--font-sans)', fontSize: 14, fontWeight: 600, marginBottom: 'var(--space-5)' }}>
        <Icon name="chevronRight" size={16} style={{ transform: 'rotate(180deg)' }} /> Back to shop
      </button>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-8)', alignItems: 'start' }}>
        {/* gallery */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
          <div style={{ aspectRatio: '1/1', borderRadius: 'var(--radius-xl)', background: 'radial-gradient(120% 120% at 30% 20%, var(--choco-500), var(--choco-800))', position: 'relative', boxShadow: 'var(--shadow-md)' }}>
            <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--choco-200)', fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', opacity: 0.6 }}>Product photo</div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 'var(--space-3)' }}>
            {[0, 1, 2, 3].map((i) => (
              <div key={i} style={{ aspectRatio: '1/1', borderRadius: 'var(--radius-md)', background: `radial-gradient(120% 120% at 30% 20%, var(--choco-${400 + i * 100}), var(--choco-800))`, cursor: 'pointer', boxShadow: i === 0 ? 'inset 0 0 0 2px var(--brand)' : 'none' }} />
            ))}
          </div>
        </div>

        {/* buy box */}
        <div>
          <div style={{ display: 'flex', gap: 'var(--space-2)', marginBottom: 'var(--space-4)' }}>
            {product.origin && <Tag tone="origin" icon={<Icon name="leaf" size={12} />}>{product.origin}</Tag>}
            {product.cocoa && <Tag tone="accent">{product.cocoa}</Tag>}
          </div>
          <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-4xl)', lineHeight: 1.05, margin: '0 0 12px', color: 'var(--text-strong)', fontWeight: 400 }}>{product.name}</h1>
          <div style={{ marginBottom: 'var(--space-4)' }}><RatingStars value={product.rating} count={product.count} showValue size={18} /></div>
          <p style={{ fontSize: 'var(--text-lg)', lineHeight: 1.6, color: 'var(--text-body)', margin: '0 0 var(--space-5)', maxWidth: '46ch' }}>{product.blurb}</p>
          <div style={{ marginBottom: 'var(--space-5)' }}><PriceTag amount={product.price} was={product.was} size="lg" /></div>

          <div style={{ display: 'flex', gap: 'var(--space-4)', marginBottom: 'var(--space-5)', maxWidth: 420 }}>
            <div style={{ flex: '0 0 auto' }}><QuantityStepper value={qty} onChange={setQty} /></div>
            <Select options={['70g bar', '100g bar', '3-bar bundle']} style={{ minWidth: 150 }} aria-label="Size" />
          </div>

          <div style={{ display: 'flex', gap: 'var(--space-3)', marginBottom: 'var(--space-6)' }}>
            <Button variant="accent" size="lg" block iconLeft={<Icon name="bag" size={20} />} onClick={() => onAdd(product, qty)}>Add {qty} to basket</Button>
            <IconButton label="Wishlist" variant="outline" size="lg" onClick={() => setWish((w) => !w)} style={wish ? { color: 'var(--berry-500)' } : undefined}><Icon name="heart" /></IconButton>
          </div>

          <div style={{ borderTop: '1px solid var(--border-subtle)', paddingTop: 'var(--space-5)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            {[['truck', 'Free delivery over €35 — packed cold and insulated.'], ['leaf', 'Traceable single-origin beans, tempered by hand.'], ['gift', 'Add a hand-written gift note at checkout.']].map(([ic, tx]) => (
              <div key={tx} style={{ display: 'flex', gap: 12, alignItems: 'center', color: 'var(--text-body)', fontSize: 14 }}>
                <span style={{ color: 'var(--brand)', display: 'inline-flex' }}><Icon name={ic} size={19} /></span>{tx}
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* related */}
      <section style={{ marginTop: 'var(--space-10)' }}>
        <SectionHeading eyebrow="You might also like" title="More from the atelier" style={{ marginBottom: 'var(--space-6)' }} />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 'var(--space-5)' }}>
          {related.map((p) => (
            <ProductCard key={p.id} name={p.name} origin={p.origin} cocoa={p.cocoa} price={p.price} was={p.was} badge={p.badge} rating={p.rating} count={p.count}
              onAdd={() => onAdd(p, 1)} onClick={() => onOpenProduct(p)} style={{ cursor: 'pointer' }} />
          ))}
        </div>
      </section>
    </div>
  );
}

Object.assign(window, { ShopProductPage });
