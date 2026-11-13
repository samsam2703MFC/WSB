const { Icon, IconButton } = window.MisterSzokoDesignSystem_613e75;

function Dropdown({ label, items, value, onPick, mono }) {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef();
  React.useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h);
  }, []);
  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button onClick={() => setOpen((o) => !o)} style={{
        display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer',
        background: 'none', border: 'none', font: 'inherit', fontFamily: mono ? 'var(--font-mono)' : 'var(--font-sans)',
        fontSize: 13, fontWeight: 600, color: 'var(--text-body)', padding: '6px 4px',
      }}>{label}<Icon name="chevronDown" size={14} /></button>
      {open && (
        <div style={{ position: 'absolute', top: '100%', right: 0, marginTop: 6, background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', boxShadow: 'var(--shadow-lg)', padding: 6, minWidth: 150, zIndex: 50 }}>
          {items.map((it) => (
            <button key={it.v} onClick={() => { onPick(it.v); setOpen(false); }} style={{
              display: 'flex', width: '100%', alignItems: 'center', justifyContent: 'space-between', gap: 10,
              background: it.v === value ? 'var(--brand-quiet)' : 'transparent', border: 'none', cursor: 'pointer',
              borderRadius: 'var(--radius-sm)', padding: '9px 11px', font: 'inherit', fontSize: 14,
              color: 'var(--text-body)', textAlign: 'left',
            }}>{it.n}{it.v === value && <Icon name="check" size={15} style={{ color: 'var(--brand)' }} />}</button>
          ))}
        </div>
      )}
    </div>
  );
}

function Header({ cartCount, onNav, onOpenCart, lang, setLang, cur, setCur, onSearch, query }) {
  return (
    <header style={{ position: 'sticky', top: 0, zIndex: 30, background: 'rgba(251,246,239,0.85)', backdropFilter: 'blur(12px)', borderBottom: '1px solid var(--border-subtle)' }}>
      <div style={{ background: 'var(--choco-800)', color: 'var(--cream-100)', display: 'flex', justifyContent: 'center', gap: 28, alignItems: 'center', fontFamily: 'var(--font-mono)', fontSize: 12, letterSpacing: '0.1em', textTransform: 'uppercase', padding: '7px 16px' }}>
        <span>Czekolada kuwertura · Dla profesjonalistów cukiernictwa</span>
        <span className="ms-anno2" style={{ opacity: 0.6 }}>·</span>
        <span className="ms-anno2">Dostawa w Polsce · Darmowa od {window.MSlib.fmt(cur, window.MS.config.freeShip[cur])}</span>
      </div>
      <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: '12px var(--space-6)', display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-4)' }}>
        <a onClick={() => onNav('home')} style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 12 }}>
          <img src={window.LOGO_SRC} alt="Mister Szoko" style={{ height: 46 }} />
          <span className="ms-tag" style={{ fontFamily: 'var(--font-display)', fontSize: 17, fontWeight: 700, color: 'var(--choco-700)' }}>Mister Szoko <span style={{ color: 'var(--caramel-600)', fontWeight: 500 }}>— wasz czekoladowy Król!</span></span>
        </a>
        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
          <div className="ms-search" style={{ position: 'relative', display: 'flex', alignItems: 'center', background: 'var(--surface-card)', borderRadius: 'var(--radius-pill)', boxShadow: 'inset 0 0 0 1.5px var(--border-default)', padding: '6px 12px', gap: 6 }}>
            <Icon name="search" size={16} style={{ color: 'var(--text-muted)' }} />
            <input value={query} onChange={(e) => onSearch(e.target.value)} placeholder="Szukaj…" style={{ border: 'none', outline: 'none', background: 'transparent', font: 'inherit', fontFamily: 'var(--font-sans)', fontSize: 14, width: 130 }} />
          </div>
          <Dropdown label={lang.toUpperCase()} value={lang} onPick={setLang} mono items={window.MS.langs.map((l) => ({ v: l.c, n: l.n }))} />
          <Dropdown label={cur} value={cur} onPick={setCur} mono items={Object.keys(window.MS.currencies).map((c) => ({ v: c, n: c }))} />
          <IconButton label="Konto" variant="ghost" onClick={() => onNav('login')}><Icon name="user" /></IconButton>
          <div style={{ position: 'relative' }}>
            <IconButton label="Koszyk" variant="soft" onClick={onOpenCart}><Icon name="bag" /></IconButton>
            {cartCount > 0 && <span style={{ position: 'absolute', top: -3, right: -3, minWidth: 20, height: 20, padding: '0 5px', background: 'var(--berry-500)', color: '#fff', borderRadius: 999, fontFamily: 'var(--font-mono)', fontSize: 11, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{cartCount}</span>}
          </div>
        </div>
      </div>
    </header>
  );
}

Object.assign(window, { Header, Dropdown });
