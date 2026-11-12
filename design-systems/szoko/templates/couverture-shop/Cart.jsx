const { Button, IconButton, Icon, QuantityStepper } = window.MisterSzokoDesignSystem_613e75;
const { fmt, prod } = window.MSlib;

// ---- upsell computations ----
function formatUpsell(lines, cur) {
  // if several 2,5 kg sacs of one ref cost more/kg than a 10 kg sac, suggest upgrade
  const out = [];
  Object.values(lines).forEach((l) => {
    if (l.v.key === 's25' && l.qty >= 4) {
      const p = prod(l.pid); const big = p.variants.find((x) => x.key === 's10');
      const smallCost = l.v.netto[cur] * l.qty;
      const setsOf4 = Math.floor((l.qty * l.v.kg) / big.kg);
      if (setsOf4 >= 1) {
        const save = Math.round((smallCost - big.netto[cur] * setsOf4) * 100) / 100;
        if (save > 0) out.push({ pid: l.pid, name: p.name, save, big });
      }
    }
  });
  return out;
}
function bundleSuggest(lines) {
  return window.MS.bundles.find((b) => b.items.every((it) => lines[it.pid + '_' + it.vkey]));
}

function CartDrawer({ open, lines, cur, onClose, onQty, onRemove, onCheckout, onAdd, onUpgrade }) {
  const items = Object.values(lines);
  const subtotal = items.reduce((s, l) => s + l.v.netto[cur] * l.qty, 0);
  const thr = window.MS.config.freeShip[cur];
  const toFree = Math.max(0, thr - subtotal);
  const pct = Math.min(100, (subtotal / thr) * 100);
  const ups = formatUpsell(lines, cur);
  const inCart = new Set(items.map((l) => l.pid));
  const suggestions = [...new Set(items.flatMap((l) => window.MS.crossSell[l.pid] || []))].filter((id) => !inCart.has(id)).slice(0, 2).map(prod);

  return (
    <>
      <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 40, background: 'rgba(46,22,12,0.55)', backdropFilter: 'blur(3px)', opacity: open ? 1 : 0, pointerEvents: open ? 'auto' : 'none', transition: 'opacity var(--dur-base) var(--ease-out)' }} />
      <aside style={{ position: 'fixed', top: 0, right: 0, bottom: 0, width: 'min(480px, 94vw)', zIndex: 41, background: 'var(--bg-page)', boxShadow: 'var(--shadow-xl)', transform: open ? 'translateX(0)' : 'translateX(100%)', transition: 'transform var(--dur-slow) var(--ease-out)', display: 'flex', flexDirection: 'column' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: 'var(--space-5) var(--space-6)', borderBottom: '1px solid var(--border-subtle)' }}>
          <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-2xl)', margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Twój koszyk</h2>
          <IconButton label="Zamknij" variant="ghost" onClick={onClose}><Icon name="x" /></IconButton>
        </div>

        <div style={{ padding: 'var(--space-4) var(--space-6)', background: 'var(--surface-raised)' }}>
          <div style={{ fontSize: 13, color: 'var(--text-body)', marginBottom: 8 }}>{toFree > 0 ? <>Jeszcze <strong>{fmt(cur, toFree)}</strong> do darmowej dostawy</> : <strong>Darmowa dostawa odblokowana ✓</strong>}</div>
          <div style={{ height: 6, borderRadius: 999, background: 'var(--cream-300)', overflow: 'hidden' }}><div style={{ height: '100%', width: `${pct}%`, background: 'var(--accent)', borderRadius: 999, transition: 'width var(--dur-slow) var(--ease-out)' }} /></div>
        </div>

        <div style={{ flex: 1, overflowY: 'auto', padding: 'var(--space-4) var(--space-6)' }}>
          {items.length === 0 && <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 'var(--space-9) 0' }}><div style={{ display: 'inline-flex', color: 'var(--border-strong)', marginBottom: 12 }}><Icon name="bag" size={40} /></div><p style={{ margin: 0 }}>Twój koszyk jest pusty.</p></div>}

          {items.map((l) => (
            <div key={l.key} style={{ display: 'flex', gap: 14, padding: 'var(--space-4) 0', borderBottom: '1px solid var(--border-subtle)' }}>
              <div style={{ width: 66, height: 66, flex: 'none', borderRadius: 'var(--radius-md)', background: `radial-gradient(120% 120% at 30% 20%, ${prod(l.pid).color}, var(--choco-900))` }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontFamily: 'var(--font-display)', fontSize: 16, color: 'var(--text-strong)' }}>{prod(l.pid).name}</div>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)', margin: '2px 0 8px' }}>{prod(l.pid).brand} · {l.v.label} · {fmt(cur, l.v.perKg[cur])}/kg</div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                  <QuantityStepper size="sm" value={l.qty} onChange={(n) => onQty(l.key, n)} />
                  <span style={{ fontFamily: 'var(--font-mono)', fontSize: 15, color: 'var(--price)', fontWeight: 500 }}>{fmt(cur, l.v.netto[cur] * l.qty)}</span>
                </div>
              </div>
              <button onClick={() => onRemove(l.key)} aria-label="Usuń" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', alignSelf: 'flex-start' }}><Icon name="x" size={16} /></button>
            </div>
          ))}

          {/* format upsell */}
          {ups.map((u) => (
            <div key={u.pid} style={{ marginTop: 14, background: 'var(--brand-quiet)', borderRadius: 'var(--radius-md)', padding: 14, display: 'flex', gap: 12, alignItems: 'center' }}>
              <div style={{ color: 'var(--brand)', flex: 'none' }}><Icon name="arrowRight" size={20} /></div>
              <div style={{ flex: 1, fontSize: 13, color: 'var(--text-body)', lineHeight: 1.4 }}>Przejdź na <b>worek 10 kg</b> ({u.name}) i zaoszczędź <b style={{ color: 'var(--berry-600)' }}>{fmt(cur, u.save)}</b>.</div>
              <Button size="sm" variant="primary" onClick={() => onUpgrade(u.pid, u.big)}>Zmień</Button>
            </div>
          ))}


          {/* cross-sell */}
          {suggestions.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.12em', color: 'var(--text-muted)', marginBottom: 10 }}>Dodaj jeszcze</div>
              {suggestions.map((sp) => (
                <div key={sp.id} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '8px 0' }}>
                  <div style={{ width: 44, height: 44, flex: 'none', borderRadius: 'var(--radius-sm)', background: `radial-gradient(120% 120% at 30% 20%, ${sp.color}, var(--choco-900))` }} />
                  <div style={{ flex: 1 }}><div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text-strong)' }}>{sp.name}</div><div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>od {fmt(cur, sp.variants[0].netto[cur])} netto</div></div>
                  <IconButton label="Dodaj" variant="soft" size="sm" onClick={() => onAdd(sp, sp.variants[0], 1)}><Icon name="plus" /></IconButton>
                </div>
              ))}
            </div>
          )}
        </div>

        {items.length > 0 && (
          <div style={{ padding: 'var(--space-5) var(--space-6)', borderTop: '1px solid var(--border-subtle)', background: 'var(--surface-card)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 4 }}><span style={{ fontSize: 15, color: 'var(--text-body)' }}>Suma netto</span><span style={{ fontFamily: 'var(--font-mono)', fontSize: 22, color: 'var(--price)', fontWeight: 500 }}>{fmt(cur, subtotal)}</span></div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 16 }}>VAT i dostawa naliczane przy płatności.</div>
            <Button variant="primary" size="lg" block iconRight={<Icon name="arrowRight" size={18} />} onClick={onCheckout}>Złóż zamówienie</Button>
          </div>
        )}
      </aside>
    </>
  );
}

Object.assign(window, { CartDrawer });
