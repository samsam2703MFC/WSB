const { Button, IconButton, Icon, PriceTag, QuantityStepper } = window.MisterSzokoDesignSystem_613e75;

function BasketDrawer({ open, lines, onClose, onQty, onRemove }) {
  const items = Object.values(lines);
  const subtotal = items.reduce((s, l) => s + parseFloat(l.product.price) * l.qty, 0);
  const freeThreshold = 35;
  const toFree = Math.max(0, freeThreshold - subtotal);
  const pct = Math.min(100, (subtotal / freeThreshold) * 100);
  return (
    <>
      <div onClick={onClose} style={{
        position: 'fixed', inset: 0, zIndex: 40,
        background: 'rgba(46,22,12,0.55)', backdropFilter: 'blur(3px)',
        opacity: open ? 1 : 0, pointerEvents: open ? 'auto' : 'none',
        transition: 'opacity var(--dur-base) var(--ease-out)',
      }} />
      <aside style={{
        position: 'fixed', top: 0, right: 0, bottom: 0, width: 'min(440px, 92vw)', zIndex: 41,
        background: 'var(--bg-page)', boxShadow: 'var(--shadow-xl)',
        transform: open ? 'translateX(0)' : 'translateX(100%)',
        transition: 'transform var(--dur-slow) var(--ease-out)',
        display: 'flex', flexDirection: 'column',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: 'var(--space-5) var(--space-6)', borderBottom: '1px solid var(--border-subtle)' }}>
          <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-2xl)', margin: 0, color: 'var(--text-strong)', fontWeight: 400 }}>Your basket</h2>
          <IconButton label="Close" variant="ghost" onClick={onClose}><Icon name="x" /></IconButton>
        </div>

        {/* free-shipping progress */}
        <div style={{ padding: 'var(--space-4) var(--space-6)', background: 'var(--surface-raised)' }}>
          <div style={{ fontSize: 13, color: 'var(--text-body)', marginBottom: 8 }}>
            {toFree > 0 ? <>Add <strong>€{toFree.toFixed(2)}</strong> for free delivery</> : <strong>You've unlocked free delivery 🎉</strong>}
          </div>
          <div style={{ height: 6, borderRadius: 999, background: 'var(--cream-300)', overflow: 'hidden' }}>
            <div style={{ height: '100%', width: `${pct}%`, background: 'var(--accent)', borderRadius: 999, transition: 'width var(--dur-slow) var(--ease-out)' }} />
          </div>
        </div>

        <div style={{ flex: 1, overflowY: 'auto', padding: 'var(--space-4) var(--space-6)' }}>
          {items.length === 0 && (
            <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 'var(--space-9) 0' }}>
              <div style={{ display: 'inline-flex', color: 'var(--border-strong)', marginBottom: 12 }}><Icon name="bag" size={40} /></div>
              <p style={{ margin: 0 }}>Your basket is empty.</p>
            </div>
          )}
          {items.map((l) => (
            <div key={l.product.id} style={{ display: 'flex', gap: 'var(--space-4)', padding: 'var(--space-4) 0', borderBottom: '1px solid var(--border-subtle)' }}>
              <div style={{ width: 74, height: 74, flex: 'none', borderRadius: 'var(--radius-md)', background: 'radial-gradient(120% 120% at 30% 20%, var(--choco-500), var(--choco-800))' }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontFamily: 'var(--font-display)', fontSize: 17, color: 'var(--text-strong)' }}>{l.product.name}</div>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)', margin: '2px 0 8px', textTransform: 'uppercase', letterSpacing: '0.08em' }}>{l.product.cocoa}</div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                  <QuantityStepper size="sm" value={l.qty} onChange={(n) => onQty(l.product.id, n)} />
                  <PriceTag amount={(parseFloat(l.product.price) * l.qty).toFixed(2)} size="sm" />
                </div>
              </div>
              <button onClick={() => onRemove(l.product.id)} aria-label="Remove" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', alignSelf: 'flex-start' }}><Icon name="x" size={16} /></button>
            </div>
          ))}
        </div>

        {items.length > 0 && (
          <div style={{ padding: 'var(--space-5) var(--space-6)', borderTop: '1px solid var(--border-subtle)', background: 'var(--surface-card)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 4 }}>
              <span style={{ fontSize: 15, color: 'var(--text-body)' }}>Subtotal</span>
              <PriceTag amount={subtotal.toFixed(2)} size="md" />
            </div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 16 }}>Taxes &amp; shipping calculated at checkout.</div>
            <Button variant="primary" size="lg" block iconRight={<Icon name="arrowRight" size={18} />}>Checkout</Button>
          </div>
        )}
      </aside>
    </>
  );
}

Object.assign(window, { BasketDrawer });
