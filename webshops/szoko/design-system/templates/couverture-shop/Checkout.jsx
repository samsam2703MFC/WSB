const { Button, Icon, Input, Select, Tag } = window.MisterSzokoDesignSystem_613e75;
const { fmt, brutto } = window.MSlib;

const STEPS = ['Adres', 'Dostawa', 'VAT i faktura', 'Płatność'];

function Stepper({ step }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 'var(--space-7)', flexWrap: 'wrap' }}>
      {STEPS.map((s, i) => (
        <React.Fragment key={s}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, opacity: i <= step ? 1 : 0.4 }}>
            <span style={{ width: 26, height: 26, borderRadius: 999, background: i < step ? 'var(--success)' : i === step ? 'var(--brand)' : 'var(--cream-300)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 700 }}>{i < step ? <Icon name="check" size={15} /> : i + 1}</span>
            <span style={{ fontSize: 14, fontWeight: 600, color: i === step ? 'var(--text-strong)' : 'var(--text-muted)' }}>{s}</span>
          </div>
          {i < STEPS.length - 1 && <span style={{ flex: '0 0 24px', height: 1.5, background: 'var(--border-default)' }} />}
        </React.Fragment>
      ))}
    </div>
  );
}

// fake VIES validation
function ViesBox({ cur, onResult, result }) {
  const [isCompany, setIsCompany] = React.useState(false);
  const [vat, setVat] = React.useState('');
  const [state, setState] = React.useState('idle'); // idle|checking|ok|down
  const check = () => {
    setState('checking');
    setTimeout(() => {
      const clean = vat.replace(/\s/g, '').toUpperCase();
      const cc = clean.slice(0, 2);
      const eu = ['DE', 'CZ', 'SK', 'FR', 'NL', 'BE', 'AT', 'IT', 'ES', 'LT'];
      if (!/^[A-Z]{2}[A-Z0-9]{6,}$/.test(clean)) { setState('down'); onResult(null); return; }
      const isPL = cc === 'PL';
      const reverse = eu.includes(cc) && !isPL;
      setState('ok');
      onResult({ vat: clean, cc, isPL, reverse, name: 'Cukiernia Demo Sp. z o.o.', addr: 'ul. Przykładowa 12, Warszawa', when: new Date().toLocaleString('fr-FR'), ref: 'WAPIAAAA-' + Math.floor(Math.random() * 1e6) });
    }, 1100);
  };
  return (
    <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-sm)' }}>
      <label style={{ display: 'flex', gap: 12, alignItems: 'flex-start', cursor: 'pointer', marginBottom: isCompany ? 18 : 0 }}>
        <input type="checkbox" checked={isCompany} onChange={(e) => { setIsCompany(e.target.checked); if (!e.target.checked) { setState('idle'); onResult(null); } }} style={{ width: 20, height: 20, marginTop: 2, accentColor: 'var(--brand)' }} />
        <span><b style={{ color: 'var(--text-strong)' }}>Kupuję jako firma</b><br /><span style={{ fontSize: 13, color: 'var(--text-muted)' }}>Weryfikacja numeru VAT UE w systemie VIES (Komisja Europejska).</span></span>
      </label>
      {!isCompany && <div style={{ marginTop: 14, fontSize: 13, color: 'var(--text-muted)', background: 'var(--surface-raised)', borderRadius: 'var(--radius-sm)', padding: '10px 12px' }}>Bez numeru NIP otrzymasz <b>e-paragon</b> na e-mail — zgodnie z polskimi przepisami (bez faktury).</div>}
      {isCompany && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end' }}>
            <div style={{ flex: 1 }}><Input label="Numer VAT UE" placeholder="PL1234567890 / DE123456789" value={vat} onChange={(e) => { setVat(e.target.value); setState('idle'); }} /></div>
            <Button variant="secondary" onClick={check} disabled={state === 'checking' || vat.length < 8}>{state === 'checking' ? 'Sprawdzanie…' : 'Sprawdź'}</Button>
          </div>
          {state === 'checking' && <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Łączenie z serwisem VIES…</div>}
          {state === 'down' && <div style={{ background: 'rgba(176,64,46,0.10)', color: 'var(--danger)', borderRadius: 'var(--radius-md)', padding: 12, fontSize: 13 }}>Numer nieprawidłowy lub serwis VIES niedostępny. Sprawdź format lub spróbuj ponownie.</div>}
          {state === 'ok' && result && (
            <div style={{ background: 'rgba(78,124,74,0.10)', borderRadius: 'var(--radius-md)', padding: 14, fontSize: 13.5, lineHeight: 1.5 }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', color: 'var(--success)', fontWeight: 700, marginBottom: 6 }}><Icon name="check" size={16} /> Numer prawidłowy — {result.vat}</div>
              <div style={{ color: 'var(--text-body)' }}>{result.name} · {result.addr}</div>
              <div style={{ color: 'var(--text-muted)', fontFamily: 'var(--font-mono)', fontSize: 11.5, marginTop: 6 }}>Zapytanie {result.ref} · {result.when}</div>
              {result.reverse && <div style={{ marginTop: 10 }}><Tag tone="berry">Odwrotne obciążenie — reverse charge</Tag></div>}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function Summary({ items, cur, shipping, vies }) {
  const netto = items.reduce((s, l) => s + l.v.netto[cur] * l.qty, 0);
  const reverse = vies && vies.reverse;
  const vatByRate = {};
  if (!reverse) items.forEach((l) => { const r = window.MSlib.prod(l.pid).vat; vatByRate[r] = (vatByRate[r] || 0) + l.v.netto[cur] * l.qty * r; });
  const vatTotal = reverse ? 0 : Object.values(vatByRate).reduce((a, b) => a + b, 0);
  const ship = shipping;
  const total = netto + vatTotal + ship;
  return (
    <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-sm)', position: 'sticky', top: 100 }}>
      <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 20, margin: '0 0 16px', color: 'var(--text-strong)', fontWeight: 600 }}>Podsumowanie</h3>
      {items.map((l) => (
        <div key={l.key} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '6px 0', fontSize: 13.5 }}>
          <span style={{ color: 'var(--text-body)' }}>{window.MSlib.prod(l.pid).name} <span style={{ color: 'var(--text-muted)' }}>×{l.qty} · {l.v.label}</span></span>
          <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-strong)' }}>{fmt(cur, l.v.netto[cur] * l.qty)}</span>
        </div>
      ))}
      <div style={{ borderTop: '1px solid var(--border-subtle)', margin: '12px 0', paddingTop: 12, display: 'flex', flexDirection: 'column', gap: 7, fontFamily: 'var(--font-mono)', fontSize: 13.5 }}>
        <Row k="Suma netto" v={fmt(cur, netto)} />
        {reverse ? <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--berry-600)' }}><span>VAT — odwrotne obciążenie</span><span>0,00</span></div>
          : Object.entries(vatByRate).map(([r, amt]) => <Row key={r} k={`VAT ${Math.round(r*100)}%`} v={fmt(cur, amt)} />)}
        <Row k="Dostawa" v={ship === 0 ? 'Gratis' : fmt(cur, ship)} />
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', borderTop: '2px solid var(--border-default)', paddingTop: 12 }}>
        <span style={{ fontWeight: 700, color: 'var(--text-strong)' }}>Razem brutto</span>
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 24, fontWeight: 600, color: 'var(--price)' }}>{fmt(cur, total)}</span>
      </div>
      {reverse && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 10, lineHeight: 1.4 }}>Adnotacja na fakturze: „odwrotne obciążenie / reverse charge”.</div>}
    </div>
  );
}
function Row({ k, v }) { return <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-body)' }}><span>{k}</span><span>{v}</span></div>; }

function Checkout({ items, cur, onBack, onDone }) {
  const [step, setStep] = React.useState(0);
  const [vies, setVies] = React.useState(null);
  const [pay, setPay] = React.useState(cur === 'PLN' ? 'blik' : 'card');
  const [method, setMethod] = React.useState('std');
  const [locker, setLocker] = React.useState(null);
  const netto = items.reduce((s, l) => s + l.v.netto[cur] * l.qty, 0);
  const base = netto >= window.MS.config.freeShip[cur] ? 0 : window.MS.config.shipping[cur];
  const shipping = method === 'inpost' ? (cur === 'PLN' ? 15.9 : 3.7) : method === 'exp' ? base + (cur === 'PLN' ? 20 : 4.7) : base;

  const next = () => step < 3 ? setStep(step + 1) : onDone({ vies, pay, cur });
  const back = () => step > 0 ? setStep(step - 1) : onBack();

  return (
    <div style={{ maxWidth: 'var(--container)', margin: '0 auto', padding: 'var(--space-6)' }}>
      <button onClick={back} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', fontSize: 14, fontWeight: 600, marginBottom: 'var(--space-5)', fontFamily: 'var(--font-sans)' }}><Icon name="chevronRight" size={16} style={{ transform: 'rotate(180deg)' }} /> {step === 0 ? 'Wstecz' : STEPS[step - 1]}</button>
      <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-3xl)', margin: '0 0 var(--space-6)', color: 'var(--text-strong)', fontWeight: 600 }}>Zamówienie</h1>
      <Stepper step={step} />
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 'var(--space-6)', alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          {step === 0 && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
              <Input label="Imię" defaultValue="Anna" /><Input label="Nazwisko" defaultValue="Kowalska" />
              <div style={{ gridColumn: '1/-1' }}><Input label="Email" defaultValue="anna@cukierniademo.pl" /></div>
              <div style={{ gridColumn: '1/-1' }}><Input label="Adres" defaultValue="ul. Przykładowa 12" /></div>
              <Input label="Kod pocztowy" defaultValue="00-001" /><Input label="Miasto" defaultValue="Warszawa" />
              <div style={{ gridColumn: '1/-1' }}><Select label="Kraj" options={['Polska']} /></div>
            </div>
          )}
          {step === 1 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {[['std', 'Kurier — 48 h', base === 0 ? 'Gratis' : fmt(cur, base)], ['exp', 'Kurier ekspres — 24 h', fmt(cur, base + (cur === 'PLN' ? 20 : 4.7))], ['inpost', 'InPost Paczkomat® — 24/7', fmt(cur, cur === 'PLN' ? 15.9 : 3.7)]].map(([k, t, p]) => (
                <div key={k} onClick={() => setMethod(k)} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '16px 18px', borderRadius: 'var(--radius-md)', background: 'var(--surface-card)', boxShadow: method === k ? 'inset 0 0 0 2px var(--brand)' : 'inset 0 0 0 1.5px var(--border-default)', cursor: 'pointer', transition: 'box-shadow var(--dur-fast) var(--ease-out)' }}>
                  <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                    {k === 'inpost' ? <span style={{ background: '#FFCB04', color: '#3F3F3F', fontWeight: 800, fontSize: 12, borderRadius: 6, padding: '5px 8px', letterSpacing: '0.02em' }}>InPost</span> : <Icon name="truck" size={22} style={{ color: 'var(--brand)' }} />}
                    <b style={{ color: 'var(--text-strong)' }}>{t}</b>
                  </div>
                  <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--price)' }}>{p}</span>
                </div>
              ))}
              {method === 'inpost' && (
                <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', padding: 16, boxShadow: 'inset 0 0 0 1.5px var(--border-default)' }}>
                  <Input label="Znajdź paczkomat" placeholder="Kod pocztowy lub ulica…" icon={<Icon name="search" size={17} />} />
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
                    {[['WAW04A', 'ul. Przykładowa 10, Warszawa', '120 m'], ['WAW112M', 'al. Czekoladowa 3, Warszawa', '450 m'], ['WAW87B', 'ul. Kakaowa 21, Warszawa', '800 m']].map(([id, addr, dist]) => (
                      <div key={id} onClick={() => setLocker(id)} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 'var(--radius-sm)', cursor: 'pointer', background: locker === id ? 'var(--brand-quiet)' : 'transparent', boxShadow: locker === id ? 'inset 0 0 0 1.5px var(--brand)' : 'inset 0 0 0 1px var(--border-subtle)' }}>
                        <b style={{ fontFamily: 'var(--font-mono)', fontSize: 13, color: 'var(--text-strong)' }}>{id}</b>
                        <span style={{ flex: 1, fontSize: 13.5, color: 'var(--text-body)' }}>{addr}</span>
                        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>{dist}</span>
                        {locker === id && <Icon name="check" size={16} style={{ color: 'var(--success)' }} />}
                      </div>
                    ))}
                  </div>
                </div>
              )}
              <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Dostawa tylko w Polsce. Przesyłki ubezpieczone.</div>
            </div>
          )}
          {step === 2 && <ViesBox cur={cur} result={vies} onResult={setVies} />}
          {step === 3 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {cur === 'PLN' && <PayOpt id="blik" cur={cur} sel={pay} setSel={setPay} title="BLIK" desc="Płatność natychmiastowa (tylko PLN)" />}
              <PayOpt id="card" cur={cur} sel={pay} setSel={setPay} title="Karta płatnicza" desc="Visa / Mastercard przez Stripe" />
              {pay === 'card' && <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', padding: 18, boxShadow: 'inset 0 0 0 1.5px var(--border-default)', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}><div style={{ gridColumn: '1/-1' }}><Input label="Numer karty" placeholder="4242 4242 4242 4242" /></div><Input label="Ważność" placeholder="12/28" /><Input label="CVC" placeholder="123" /></div>}
              {pay === 'blik' && <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', padding: 18, boxShadow: 'inset 0 0 0 1.5px var(--border-default)', maxWidth: 220 }}><Input label="Kod BLIK" placeholder="123 456" /></div>}
              <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Tryb testowy Stripe — brak rzeczywistych płatności.</div>
            </div>
          )}
          <div><Button variant="accent" size="lg" onClick={next} iconRight={<Icon name={step === 3 ? 'check' : 'arrowRight'} size={18} />} disabled={step === 1 && method === 'inpost' && !locker}>{step === 3 ? 'Zapłać ' + fmt(cur, netto + (vies && vies.reverse ? 0 : items.reduce((s, l) => s + l.v.netto[cur] * l.qty * window.MSlib.prod(l.pid).vat, 0)) + shipping) : 'Dalej'}</Button></div>
        </div>
        <Summary items={items} cur={cur} shipping={shipping} vies={vies} />
      </div>
    </div>
  );
}
function PayOpt({ id, sel, setSel, title, desc }) {
  const on = sel === id;
  return <div onClick={() => setSel(id)} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '16px 18px', borderRadius: 'var(--radius-md)', background: 'var(--surface-card)', boxShadow: on ? 'inset 0 0 0 2px var(--brand)' : 'inset 0 0 0 1.5px var(--border-default)', cursor: 'pointer' }}><span style={{ width: 18, height: 18, borderRadius: 999, border: '2px solid var(--brand)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{on && <span style={{ width: 9, height: 9, borderRadius: 999, background: 'var(--brand)' }} />}</span><div><b style={{ color: 'var(--text-strong)' }}>{title}</b><div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{desc}</div></div></div>;
}

function Confirmation({ order, cur, onHome }) {
  return (
    <div style={{ maxWidth: 720, margin: '0 auto', padding: 'var(--space-9) var(--space-6)', textAlign: 'center' }}>
      <div style={{ width: 72, height: 72, borderRadius: 999, background: 'var(--success)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 24px' }}><Icon name="check" size={38} /></div>
      <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 'var(--text-4xl)', margin: '0 0 12px', color: 'var(--text-strong)', fontWeight: 600 }}>Dziękujemy, zamówienie potwierdzone</h1>
      <p style={{ fontSize: 'var(--text-lg)', color: 'var(--text-muted)', margin: '0 0 8px' }}>Płatność {order.pay === 'blik' ? 'BLIK' : 'kartą'} przyjęta (test). Wysłaliśmy e-mail z potwierdzeniem.</p>
      <div style={{ fontFamily: 'var(--font-mono)', fontSize: 14, color: 'var(--text-body)', marginBottom: 28 }}>{order.vies ? <>Faktura <b>FV/2026/00042</b> · KSeF 6250721-8842-A3{order.vies.reverse ? ' · odwrotne obciążenie' : ''}</> : <>E-paragon <b>PAR/2026/01205</b> · wysłany na e-mail (zgodnie z ustawą)</>}</div>
      <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap' }}>
        <Button variant="primary" iconLeft={<Icon name="arrowRight" size={18} />}>{order.vies ? 'Pobierz fakturę PDF' : 'Pobierz e-paragon'}</Button>
        <Button variant="secondary" onClick={onHome}>Kontynuuj zakupy</Button>
      </div>
      <div style={{ marginTop: 32, background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-sm)', textAlign: 'left' }}>
        <b style={{ color: 'var(--text-strong)' }}>Załóż konto jednym kliknięciem</b>
        <p style={{ fontSize: 14, color: 'var(--text-muted)', margin: '6px 0 14px' }}>Dostęp do faktur i szybsze zamówienia następnym razem.</p>
        <Button variant="accent" size="sm">Załóż konto firmowe</Button>
      </div>
    </div>
  );
}

Object.assign(window, { Checkout, Confirmation });
