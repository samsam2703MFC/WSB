const { Icon, Button } = window.MisterSzokoDesignSystem_613e75;
const { fmt } = window.MSlib;

function Footer({ cur }) {
  const cols = [['Sklep', ['Czekolada ciemna', 'Czekolada mleczna', 'Czekolada biała', 'Specjalności']], ['Strefa pro', ['Załóż konto', 'Ceny degresywne', 'Faktury i VAT', 'Dostawa']], ['O nas', ['Nasza historia', 'Pochodzenie ziarna', 'Kontakt', 'Regulamin']]];
  return (
    <footer style={{ background: 'var(--choco-900)', color: 'var(--cream-200)', marginTop: 'var(--space-9)' }}>
      <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-9) var(--space-6) var(--space-6)', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 'var(--space-6)' }}>
        <div><div style={{ fontFamily: 'var(--font-display)', fontSize: 24, color: 'var(--cream-50)' }}>Mister Szoko</div><p style={{ maxWidth: 260, marginTop: 12, lineHeight: 1.6, fontSize: 14, color: 'var(--choco-200)' }}>Czekolada kuwertura dla profesjonalistów cukiernictwa. Dostawa w Polsce, zgodne faktury.</p></div>
        {cols.map(([h, links]) => (
          <div key={h}><div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--caramel-400)', marginBottom: 14 }}>{h}</div><ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 10 }}>{links.map((l) => <li key={l}><a href="#" style={{ color: 'var(--cream-200)', textDecoration: 'none', fontSize: 14 }} onMouseEnter={(e) => e.currentTarget.style.color = 'var(--caramel-400)'} onMouseLeave={(e) => e.currentTarget.style.color = 'var(--cream-200)'}>{l}</a></li>)}</ul></div>
        ))}
      </div>
      <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-5) var(--space-6)', borderTop: '1px solid rgba(255,255,255,0.1)', display: 'flex', justifyContent: 'space-between', fontSize: 13, color: 'var(--choco-300)' }}><span>© 2026 Mister Szoko Sp. z o.o. · NIP PL5252525252</span><span>PL · EN · CS · UK · {cur}</span></div>
    </footer>
  );
}

function Toast({ msg }) {
  return <div style={{ position: 'fixed', bottom: 24, left: '50%', transform: `translateX(-50%) translateY(${msg ? '0' : '20px'})`, opacity: msg ? 1 : 0, pointerEvents: 'none', zIndex: 60, background: 'var(--choco-800)', color: 'var(--cream-50)', padding: '14px 22px', borderRadius: 'var(--radius-pill)', boxShadow: 'var(--shadow-lg)', display: 'flex', alignItems: 'center', gap: 10, fontSize: 15, fontWeight: 600, transition: 'all var(--dur-base) var(--ease-soft)' }}><span style={{ color: 'var(--success)', display: 'inline-flex' }}><Icon name="check" size={20} /></span>{msg}</div>;
}

function App() {
  const [view, setView] = React.useState('home');
  const [product, setProduct] = React.useState(null);
  const [cart, setCart] = React.useState({});
  const [cartOpen, setCartOpen] = React.useState(false);
  const [toast, setToast] = React.useState('');
  const [lang, setLang] = React.useState('pl');
  const [cur, setCur] = React.useState('PLN');
  const [query, setQuery] = React.useState('');
  const [order, setOrder] = React.useState(null);
  const [remise, setRemise] = React.useState(0);
  const tt = React.useRef();

  const count = Object.values(cart).reduce((s, l) => s + l.qty, 0);
  const items = Object.values(cart);
  const flash = (m) => { setToast(m); clearTimeout(tt.current); tt.current = setTimeout(() => setToast(''), 2200); };
  const add = (p, v, qty = 1) => { const key = p.id + '_' + v.key; setCart((c) => ({ ...c, [key]: { key, pid: p.id, v, qty: (c[key]?.qty || 0) + qty } })); flash(`${p.name} (${v.label}) — dodano do koszyka`); };
  const setQty = (key, n) => setCart((c) => ({ ...c, [key]: { ...c[key], qty: n } }));
  const remove = (key) => setCart((c) => { const n = { ...c }; delete n[key]; return n; });
  const upgrade = (pid, big) => { const p = window.MSlib.prod(pid); setCart((c) => { const n = { ...c }; delete n[pid + '_s25']; const k = pid + '_s10'; n[k] = { key: k, pid, v: big, qty: (n[k]?.qty || 0) + 1 }; return n; }); flash('Opakowanie zaktualizowane'); };
  const openProduct = (p) => { setProduct(p); setView('product'); window.scrollTo({ top: 0 }); };
  const nav = (v) => { setView(v); window.scrollTo({ top: 0 }); };

    const showChrome = !['login', 'checkout', 'confirm'].includes(view);
  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-page)' }}>
      {view !== 'login' && <window.Header cartCount={count} onNav={nav} onOpenCart={() => setCartOpen(true)} lang={lang} setLang={setLang} cur={cur} setCur={setCur} query={query} onSearch={(q) => { setQuery(q); if (view !== 'home') nav('home'); }} />}
      {view === 'home' && <window.Home cur={cur} query={query} onOpen={openProduct} onAdd={add} onOpenBundle={() => {}} onPro={() => nav('login')} />}
      {view === 'product' && product && <window.ProductPage product={product} cur={cur} onBack={() => nav('home')} onAdd={add} onOpen={openProduct} />}
      {view === 'login' && <window.Login onBack={() => nav('home')} onLogin={() => nav('pro')} />}
      {view === 'pro' && <window.ProSpace cur={cur} remise={remise} setRemise={setRemise} />}
      {view === 'checkout' && <window.Checkout items={items} cur={cur} onBack={() => { setCartOpen(true); nav('home'); }} onDone={(o) => { setOrder(o); nav('confirm'); setCart({}); }} />}
      {view === 'confirm' && order && <window.Confirmation order={order} cur={cur} onHome={() => nav('home')} />}
      {showChrome && <Footer cur={cur} />}
      <window.CartDrawer open={cartOpen} lines={cart} cur={cur} onClose={() => setCartOpen(false)} onQty={setQty} onRemove={remove} onAdd={add} onUpgrade={upgrade} onCheckout={() => { setCartOpen(false); nav('checkout'); }} />
      <Toast msg={toast} />
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
