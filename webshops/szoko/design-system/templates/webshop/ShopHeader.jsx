const { Icon, IconButton, Button, Tag } = window.MisterSzokoDesignSystem_613e75;

function ShopHeader({ cartCount, onNav, onOpenCart, active }) {
  const links = ['Shop', 'Collections', 'Our story', 'Gifting'];
  return (
    <header style={{
      position: 'sticky', top: 0, zIndex: 30,
      background: 'rgba(251,246,239,0.82)', backdropFilter: 'blur(12px)',
      borderBottom: '1px solid var(--border-subtle)',
    }}>
      {/* announcement bar */}
      <div style={{
        background: 'var(--choco-800)', color: 'var(--cream-100)', textAlign: 'center',
        fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: '0.14em',
        textTransform: 'uppercase', padding: '7px 16px',
      }}>Free delivery over €35 · Packed cold, shipped fast</div>

      <div style={{
        maxWidth: 'var(--container)', margin: '0 auto', padding: '14px var(--space-6)',
        display: 'flex', alignItems: 'center', gap: 'var(--space-6)',
      }}>
        <a onClick={() => onNav('home')} style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', textDecoration: 'none' }}>
          <img src="../../assets/logo.png" alt="Mister Szoko" style={{ height: 46, width: 'auto' }} />
        </a>

        <nav style={{ display: 'flex', gap: 'var(--space-6)', marginLeft: 'var(--space-4)' }}>
          {links.map((l) => (
            <a key={l} onClick={() => onNav('home')} style={{
              fontFamily: 'var(--font-sans)', fontSize: 15, fontWeight: 600, cursor: 'pointer',
              color: 'var(--text-body)', textDecoration: 'none', padding: '6px 0',
              borderBottom: '2px solid transparent',
            }}
            onMouseEnter={(e) => e.currentTarget.style.color = 'var(--brand)'}
            onMouseLeave={(e) => e.currentTarget.style.color = 'var(--text-body)'}>{l}</a>
          ))}
        </nav>

        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
          <IconButton label="Search" variant="ghost"><Icon name="search" /></IconButton>
          <IconButton label="Account" variant="ghost"><Icon name="user" /></IconButton>
          <div style={{ position: 'relative' }}>
            <IconButton label="Basket" variant="soft" onClick={onOpenCart}><Icon name="bag" /></IconButton>
            {cartCount > 0 && (
              <span style={{
                position: 'absolute', top: -3, right: -3, minWidth: 20, height: 20, padding: '0 5px',
                background: 'var(--berry-500)', color: '#fff', borderRadius: 999,
                fontFamily: 'var(--font-mono)', fontSize: 11, fontWeight: 700,
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>{cartCount}</span>
            )}
          </div>
        </div>
      </div>
    </header>
  );
}

Object.assign(window, { ShopHeader });
