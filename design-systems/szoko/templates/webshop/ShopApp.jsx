const { Icon, Button } = window.MisterSzokoDesignSystem_613e75;

function Footer() {
  const cols = [
    ['Shop', ['All chocolate', 'Dark bars', 'Milk bars', 'Gift boxes', 'Vegan']],
    ['Company', ['Our story', 'The atelier', 'Sustainability', 'Stockists']],
    ['Help', ['Delivery', 'Returns', 'Contact', 'FAQ']],
  ];
  return (
    <footer style={{ background: 'var(--choco-900)', color: 'var(--cream-200)', marginTop: 'var(--space-9)' }}>
      <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-9) var(--space-6) var(--space-6)', display: 'grid', gridTemplateColumns: '1.4fr 1fr 1fr 1fr', gap: 'var(--space-7)' }}>
        <div>
          <img src="../../assets/logo.png" alt="Mister Szoko" style={{ height: 60, filter: 'brightness(0) invert(1)', opacity: 0.92 }} />
          <p style={{ maxWidth: 260, marginTop: 16, lineHeight: 1.6, fontSize: 14, color: 'var(--choco-200)' }}>Small-batch, bean-to-bar chocolate. Tempered by hand, packed cold, shipped fast.</p>
        </div>
        {cols.map(([h, links]) => (
          <div key={h}>
            <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--caramel-400)', marginBottom: 14 }}>{h}</div>
            <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 10 }}>
              {links.map((l) => <li key={l}><a href="#" style={{ color: 'var(--cream-200)', textDecoration: 'none', fontSize: 14 }} onMouseEnter={(e) => e.currentTarget.style.color = 'var(--caramel-400)'} onMouseLeave={(e) => e.currentTarget.style.color = 'var(--cream-200)'}>{l}</a></li>)}
            </ul>
          </div>
        ))}
      </div>
      <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-5) var(--space-6)', borderTop: '1px solid rgba(255,255,255,0.10)', display: 'flex', justifyContent: 'space-between', fontSize: 13, color: 'var(--choco-300)' }}>
        <span>© 2026 Mister Szoko. Made with cocoa.</span>
        <span>Terms · Privacy</span>
      </div>
    </footer>
  );
}

function Toast({ msg }) {
  return (
    <div style={{
      position: 'fixed', bottom: 24, left: '50%', transform: `translateX(-50%) translateY(${msg ? '0' : '20px'})`,
      opacity: msg ? 1 : 0, pointerEvents: 'none', zIndex: 60,
      background: 'var(--choco-800)', color: 'var(--cream-50)', padding: '14px 22px',
      borderRadius: 'var(--radius-pill)', boxShadow: 'var(--shadow-lg)',
      display: 'flex', alignItems: 'center', gap: 10, fontSize: 15, fontWeight: 600,
      transition: 'all var(--dur-base) var(--ease-soft)',
    }}>
      <span style={{ color: 'var(--success)', display: 'inline-flex' }}><Icon name="check" size={20} /></span>{msg}
    </div>
  );
}

function ShopApp() {
  const [view, setView] = React.useState('home');
  const [product, setProduct] = React.useState(null);
  const [cart, setCart] = React.useState({});
  const [cartOpen, setCartOpen] = React.useState(false);
  const [toast, setToast] = React.useState('');
  const toastTimer = React.useRef();

  const count = Object.values(cart).reduce((s, l) => s + l.qty, 0);

  const flash = (m) => { setToast(m); clearTimeout(toastTimer.current); toastTimer.current = setTimeout(() => setToast(''), 2200); };
  const add = (p, qty = 1) => {
    setCart((c) => ({ ...c, [p.id]: { product: p, qty: (c[p.id]?.qty || 0) + qty } }));
    flash(`Added ${p.name} to basket`);
  };
  const setQty = (id, n) => setCart((c) => ({ ...c, [id]: { ...c[id], qty: n } }));
  const remove = (id) => setCart((c) => { const n = { ...c }; delete n[id]; return n; });
  const openProduct = (p) => { setProduct(p); setView('product'); window.scrollTo({ top: 0 }); };
  const nav = (v) => { setView(v); window.scrollTo({ top: 0 }); };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-page)' }}>
      <ShopHeader cartCount={count} onNav={nav} onOpenCart={() => setCartOpen(true)} active={view} />
      {view === 'home' && <ShopHome onOpenProduct={openProduct} onAdd={add} />}
      {view === 'product' && product && <ShopProductPage product={product} onBack={() => nav('home')} onAdd={add} onOpenProduct={openProduct} />}
      <Footer />
      <BasketDrawer open={cartOpen} lines={cart} onClose={() => setCartOpen(false)} onQty={setQty} onRemove={remove} />
      <Toast msg={toast} />
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<ShopApp />);
