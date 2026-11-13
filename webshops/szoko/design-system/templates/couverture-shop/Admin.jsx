const { Button, Icon, Input, Tag, Badge, Select, IconButton } = window.MisterSzokoDesignSystem_613e75;
const { fmt } = window.MSlib;
const ACUR = 'PLN';

function AdminLogin({ onEnter, onExit }) {
  return (
    <div style={{ minHeight: '100vh', background: 'var(--choco-900)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-8)', width: 380, boxShadow: 'var(--shadow-xl)' }}>
        <img src={window.LOGO_SRC} alt="" style={{ height: 52, marginBottom: 18 }} />
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--caramel-600)', marginBottom: 6 }}>Back-office</div>
        <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 26, margin: '0 0 22px', color: 'var(--text-strong)', fontWeight: 600 }}>Administracja</h1>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <Input label="Login" defaultValue="admin@misterszoko.pl" />
          <Input label="Hasło" type="password" defaultValue="••••••••••" />
          <Button variant="primary" size="lg" block onClick={onEnter}>Zaloguj się</Button>
          <a onClick={onExit} style={{ fontSize: 13, color: 'var(--text-muted)', cursor: 'pointer', textAlign: 'center' }}>← Wróć do sklepu</a>
        </div>
      </div>
    </div>
  );
}

const NAV = [['dash', 'Pulpit', 'user'], ['prod', 'Produkty', 'bag'], ['mag', 'Magazyn', 'truck'], ['orders', 'Zamówienia', 'truck'], ['inv', 'Faktury', 'gift'], ['clients', 'Klienci', 'user'], ['settings', 'Ustawienia', 'leaf']];

const ORDERS = [
  { id: 'FV/2026/00042', cust: 'Cukiernia Demo', total: 1240.50, cur: 'PLN', status: 'Opłacone', vat: 'PL 23%', date: '21/07', ksef: '6250721-8842-A3', pay: 'BLIK' },
  { id: 'FV/2026/00041', cust: 'Café Praha s.r.o.', total: 286.00, cur: 'EUR', status: 'Reverse charge', vat: 'CZ RC', date: '21/07', ksef: '6250721-8791-B7', pay: 'Karta' },
  { id: 'FV/2026/00040', cust: 'Horeca Kraków', total: 3980.00, cur: 'PLN', status: 'Wysłane', vat: 'PL 23%', date: '20/07', ksef: '6250720-8654-C1', pay: 'Karta' },
  { id: 'PAR/2026/01204', cust: 'A. Kowalska', total: 172.20, cur: 'PLN', status: 'Opłacone', vat: 'PL 23%', date: '20/07', ksef: null, pay: 'BLIK', doc: 'par' },
  { id: 'FV/2026/00038', cust: 'Backhaus GmbH', total: 940.00, cur: 'EUR', status: 'Reverse charge', vat: 'DE RC', date: '19/07', ksef: '6250719-8420-D9', pay: 'Przelew' },
];
function PayBadge({ pay }) {
  const styles = {
    BLIK: { background: '#000', color: '#fff' },
    Karta: { background: '#635BFF', color: '#fff' },
    Przelew: { background: 'var(--surface-sunken)', color: 'var(--text-body)' },
  };
  return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontFamily: 'var(--font-mono)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.04em', padding: '4px 9px', borderRadius: 999, whiteSpace: 'nowrap', ...styles[pay] }}>{pay === 'Karta' ? 'STRIPE · KARTA' : pay.toUpperCase()}</span>;
}

const statusTone = (s) => s === 'Opłacone' ? 'new' : s === 'Wysłane' ? 'gold' : s === 'Reverse charge' ? 'sale' : 'soft';

function OrderModal({ order: o, onClose }) {
  if (!o) return null;
  const lines = [
    { n: 'Ciemna 70% — Ghana', f: 'Karton 20 kg', q: 2, p: 416.00 },
    { n: 'Mleczna 33%', f: 'Worek 10 kg', q: 1, p: 211.20 },
  ];
  const netto = lines.reduce((s, l) => s + l.p * l.q, 0);
  const rc = o.status === 'Reverse charge';
  const vat = rc ? 0 : netto * 0.23;
  const Act = ({ icon, children, primary }) => (
    <Button variant={primary ? 'accent' : 'secondary'} size="sm" iconLeft={<Icon name={icon} size={15} />} onClick={() => window.print && null}>{children}</Button>
  );
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'rgba(46,22,12,0.55)', backdropFilter: 'blur(3px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: 'var(--bg-page)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-xl)', width: 'min(680px, 100%)', maxHeight: '90vh', overflowY: 'auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '20px 28px', borderBottom: '1px solid var(--border-subtle)', position: 'sticky', top: 0, background: 'var(--bg-page)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
            <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Zamówienie {o.id}</h3>
            <Badge tone={statusTone(o.status)}>{o.status}</Badge>
            <PayBadge pay={o.pay} />
          </div>
          <IconButton label="Zamknij" variant="ghost" onClick={onClose}><Icon name="x" /></IconButton>
        </div>
        <div style={{ padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 18 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
            <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', padding: 14, fontSize: 13.5, lineHeight: 1.55 }}>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'var(--text-muted)', marginBottom: 6 }}>Klient</div>
              <b style={{ color: 'var(--text-strong)' }}>{o.cust}</b><br />ul. Przykładowa 12, 00-001 Warszawa<br /><span style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>NIP: PL1234567890</span>
            </div>
            <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', padding: 14, fontSize: 13.5, lineHeight: 1.55 }}>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'var(--text-muted)', marginBottom: 6 }}>Dostawa i faktura</div>
              <span style={{ background: '#FFCB04', color: '#3F3F3F', fontWeight: 800, fontSize: 10.5, borderRadius: 5, padding: '3px 6px' }}>InPost</span> <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>WAW04A · nr 662 004 813</span><br />
              {o.doc === 'par' ? <>Dokument: <b>e-paragon</b> (B2C, bez faktury — zgodnie z ustawą)</> : <>KSeF: {o.ksef ? <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--success)' }}>✓ {o.ksef}</span> : <span style={{ color: 'var(--caramel-600)', fontSize: 12.5 }}>oczekuje na wysyłkę</span>}</>}
            </div>
          </div>
          <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
            {lines.map((l) => (
              <div key={l.n} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 16px', borderBottom: '1px solid var(--border-subtle)', fontSize: 13.5 }}>
                <span style={{ flex: 1, color: 'var(--text-strong)', fontWeight: 600 }}>{l.n} <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}>· {l.f}</span></span>
                <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-muted)' }}>×{l.q}</span>
                <span style={{ fontFamily: 'var(--font-mono)', minWidth: 90, textAlign: 'right' }}>{fmt('PLN', l.p * l.q)}</span>
              </div>
            ))}
            <div style={{ padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 5, fontFamily: 'var(--font-mono)', fontSize: 13 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-body)' }}><span>Suma netto</span><span>{fmt('PLN', netto)}</span></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: rc ? 'var(--berry-600)' : 'var(--text-body)' }}><span>{rc ? 'VAT — odwrotne obciążenie' : 'VAT 23%'}</span><span>{fmt('PLN', vat)}</span></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-strong)', fontWeight: 700, fontSize: 15, borderTop: '1px solid var(--border-subtle)', paddingTop: 8, marginTop: 3 }}><span>Razem brutto</span><span>{fmt('PLN', netto + vat)}</span></div>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', paddingBottom: 8 }}>
            <Act icon="arrowRight" primary>Drukuj zamówienie</Act>
            <Act icon="truck">Etykieta InPost</Act>
            <Act icon="gift">{o.doc === 'par' ? 'Pobierz e-paragon' : 'Pobierz fakturę PDF'}</Act>
            <Act icon="x">Anuluj zamówienie — e-mail do klienta</Act>
            <Act icon="user">Zablokuj klienta</Act>
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: -4, paddingBottom: 8 }}>Korekta możliwa dopiero po wystawieniu faktury (zakładka Faktury). Dopóki to zamówienie — można je anulować (klient dostaje e-mail) lub zablokować klienta.</div>
        </div>
      </div>
    </div>
  );
}

function KPI({ label, value, sub, accent }) {
  return (
    <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-5)', boxShadow: 'var(--shadow-xs)' }}>
      <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'var(--text-muted)', marginBottom: 8 }}>{label}</div>
      <div style={{ fontFamily: 'var(--font-mono)', fontSize: 27, fontWeight: 600, color: accent || 'var(--text-strong)', whiteSpace: 'nowrap' }}>{value}</div>
      <div style={{ fontSize: 12.5, color: 'var(--success)', marginTop: 4 }}>{sub}</div>
    </div>
  );
}

function Table({ head, rows, onRowClick }) {
  return (
    <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-xs)', overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
        <thead><tr style={{ background: 'var(--surface-sunken)' }}>{head.map((h) => <th key={h} style={{ textAlign: 'left', padding: '12px 16px', fontFamily: 'var(--font-mono)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'var(--text-muted)', fontWeight: 600 }}>{h}</th>)}</tr></thead>
        <tbody>{rows.map((r, i) => <tr key={i} onClick={() => onRowClick && onRowClick(i)} style={{ borderTop: '1px solid var(--border-subtle)', cursor: onRowClick ? 'pointer' : 'default' }}>{r.map((c, j) => <td key={j} style={{ padding: '13px 16px', color: 'var(--text-body)' }}>{c}</td>)}</tr>)}</tbody>
      </table>
    </div>
  );
}

function Dash() {
  const h3 = { fontFamily: 'var(--font-display)', fontSize: 20, margin: '0 0 14px', color: 'var(--text-strong)', fontWeight: 600 };
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-6)' }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 'var(--space-4)' }}>
        <KPI label="Obrót w miesiącu" value="48 320 zł" sub="▲ 12% vs poprzedni miesiąc" />
        <KPI label="Zamówienia" value="184" sub="▲ 9%" />
        <KPI label="Średni koszyk" value="262 zł" sub="▲ 4%" />
        <KPI label="Udział pakietów" value="31%" sub="▲ 6 p.p." accent="var(--caramel-600)" />
      </div>
      <div>
        <h3 style={h3}>Ostatnie zamówienia</h3>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12 }}>
          {ORDERS.map((o) => (
            <div key={o.id} style={{ background: 'var(--surface-card)', borderRadius: 10, padding: 16, boxShadow: 'var(--shadow-xs)', display: 'flex', flexDirection: 'column', gap: 8 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                <b style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>{o.id}</b>
                <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--text-muted)' }}>{o.date}</span>
              </div>
              <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-strong)' }}>{o.cust}</div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}><PayBadge pay={o.pay} /><Badge tone={statusTone(o.status)}>{o.status}</Badge></div>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 17, color: 'var(--price)', marginTop: 'auto' }}>{fmt(o.cur, o.total)}</div>
            </div>
          ))}
        </div>
      </div>
      <div>
        <h3 style={h3}>Top referencje</h3>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12 }}>
          {window.MS.products.slice(0, 5).map((p, i) => {
            const kg = [368, 296, 220, 164, 120][i];
            const w = [92, 74, 55, 41, 30][i];
            return (
              <div key={p.id} style={{ background: 'var(--surface-card)', borderRadius: 10, padding: 16, boxShadow: 'var(--shadow-xs)', display: 'flex', flexDirection: 'column', gap: 10 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <span style={{ width: 30, height: 30, flex: 'none', borderRadius: 8, background: p.color }} />
                  <b style={{ fontSize: 13.5, color: 'var(--text-strong)', lineHeight: 1.25 }}>{p.name}</b>
                </div>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>{kg} kg / mies.</div>
                <div style={{ height: 6, borderRadius: 999, background: 'var(--surface-sunken)' }}><div style={{ height: '100%', width: w + '%', borderRadius: 999, background: 'var(--caramel-500)' }} /></div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function Products() {
  const [sel, setSel] = React.useState(null);
  return (
    <div>
      <window.ProductEditModal product={sel} onClose={() => setSel(null)} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Produkty i warianty</h3>
        <Button variant="accent" size="sm" iconLeft={<Icon name="plus" size={16} />}>Nowy produkt</Button>
      </div>
      <Table head={['Referencja', 'Kategoria', '2,5 kg', '10 kg', '20 kg', 'VAT', '']} rows={window.MS.products.map((p) => [
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}><span style={{ width: 30, height: 30, borderRadius: 8, background: `radial-gradient(120% 120% at 30% 20%, ${p.color}, var(--choco-900))` }} /><div><b style={{ color: 'var(--text-strong)' }}>{p.name}</b><div style={{ fontSize: 11.5, color: 'var(--text-muted)', fontFamily: 'var(--font-mono)' }}>{p.brand} · kakao {p.cacao}</div></div></div>,
        window.MS.categories.find((c) => c.key === p.cat).label,
        ...p.variants.map((v) => <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, whiteSpace: 'nowrap' }}>{fmt(ACUR, v.netto.PLN)}<div style={{ fontSize: 10.5, color: v.qty > 20 ? 'var(--success)' : v.qty > 0 ? 'var(--caramel-600)' : 'var(--danger)' }}>{v.qty} szt.{v.arrival ? ' · dostawa ' + v.arrival : ''}</div></span>),
        <span style={{ fontFamily: 'var(--font-mono)' }}>{Math.round(p.vat * 100)}%</span>,
        <Button variant="ghost" size="sm" onClick={() => setSel(p)}>Edytuj</Button>,
      ])} />
    </div>
  );
}

function Orders() {
  const [sel, setSel] = React.useState(null);
  return (
    <div>
      <OrderModal order={sel} onClose={() => setSel(null)} />
      <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: '0 0 16px', color: 'var(--text-strong)', fontWeight: 600 }}>Zamówienia</h3>
      <Table onRowClick={(i) => setSel(ORDERS[i])} head={['Faktura', 'Data', 'Klient', 'Płatność', 'VAT', 'Razem', 'Status', 'Akcje']} rows={ORDERS.map((o) => [
        <b style={{ fontFamily: 'var(--font-mono)' }}>{o.id}</b>, o.date, o.cust,
        <PayBadge pay={o.pay} />,
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>{o.vat}</span>,
        <span style={{ fontFamily: 'var(--font-mono)' }}>{fmt(o.cur, o.total)}</span>,
        <Badge tone={statusTone(o.status)}>{o.status}</Badge>,
        <div style={{ display: 'flex', gap: 6 }}><Button variant="ghost" size="sm" onClick={() => setSel(o)}>Szczegóły</Button><Button variant="ghost" size="sm">Zwrot</Button></div>,
      ])} />
    </div>
  );
}

function Invoices() {
  return (
    <div>
      <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: '0 0 16px', color: 'var(--text-strong)', fontWeight: 600 }}>Faktury i e-paragony</h3>
      <Table head={['Numer', 'Klient', 'Kwota', 'Adnotacja', 'KSeF', 'Akcje']} rows={ORDERS.map((o) => [
        <b style={{ fontFamily: 'var(--font-mono)' }}>{o.id}</b>, o.cust,
        <span style={{ fontFamily: 'var(--font-mono)' }}>{fmt(o.cur, o.total)}</span>,
        o.doc === 'par' ? <Tag tone="accent">e-paragon · B2C</Tag> : o.status === 'Reverse charge' ? <Tag tone="berry">reverse charge</Tag> : <span style={{ color: 'var(--text-muted)', fontSize: 13 }}>VAT {o.vat}</span>,
        o.doc === 'par' ? <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>n/d — e-paragon</span> : o.ksef ? <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--success)', display: 'inline-flex', alignItems: 'center', gap: 5 }}><Icon name="check" size={13} />{o.ksef}</span> : <Button variant="secondary" size="sm">Wyślij do KSeF</Button>,
        <div style={{ display: 'flex', gap: 6 }}><Button variant="secondary" size="sm" iconLeft={<Icon name="arrowRight" size={14} />}>PDF</Button><Button variant="ghost" size="sm">Korekta</Button></div>,
      ])} />
    </div>
  );
}

function TypeTag({ t }) {
  const s = { b2b: { label: 'B2B', background: 'var(--brand-quiet)', color: 'var(--choco-600)' }, hurt: { label: 'HURTOWNIA', background: 'var(--gold-500)', color: 'var(--choco-900)' }, b2c: { label: 'B2C', background: 'var(--surface-sunken)', color: 'var(--text-body)' } }[t];
  return <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.06em', padding: '4px 9px', borderRadius: 999, background: s.background, color: s.color }}>{s.label}</span>;
}

function Clients() {
  const cs = [
    { n: 'Cukiernia Demo', v: 'PL1234567890', t: 'b2b', note: 'PL', o: 24, m: '4 120 zł', y: '38 900 zł' },
    { n: 'Café Praha s.r.o.', v: 'CZ12345678', t: 'b2b', note: 'odwrotne obciążenie', o: 8, m: '1 240 €', y: '9 830 €' },
    { n: 'Horeca Kraków', v: 'PL9876543210', t: 'hurt', note: 'PL', o: 41, m: '11 480 zł', y: '96 400 zł' },
    { n: 'A. Kowalska', v: '—', t: 'b2c', note: '', o: 3, m: '172 zł', y: '1 260 zł' },
    { n: 'Backhaus GmbH', v: 'DE123456789', t: 'hurt', note: 'odwrotne obciążenie', o: 12, m: '3 940 €', y: '31 200 €' },
  ];
  return (
    <div>
      <window.GeoSection />
      <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: '0 0 16px', color: 'var(--text-strong)', fontWeight: 600 }}>Klienci</h3>
      <Table head={['Nazwa', 'NIP / VAT UE', 'Typ', 'Zamówienia', 'Obrót (miesiąc)', 'Obrót (rok)']} rows={cs.map((c) => [
        <b style={{ color: 'var(--text-strong)' }}>{c.n}</b>,
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13 }}>{c.v}</span>,
        <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}><TypeTag t={c.t} />{c.note && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{c.note}</span>}</span>,
        c.o,
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13 }}>{c.m}</span>,
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 600, color: 'var(--text-strong)' }}>{c.y}</span>,
      ])} />
    </div>
  );
}

function Settings() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-6)' }}>
      <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 22, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Ustawienia</h3>
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
        <b style={{ color: 'var(--text-strong)' }}>Dane sprzedawcy (faktura)</b>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14, marginTop: 14 }}>
          <Input label="Nazwa firmy" defaultValue="Mister Szoko Sp. z o.o." /><Input label="NIP" defaultValue="PL5252525252" />
          <div style={{ gridColumn: '1/-1' }}><Input label="Adres" defaultValue="ul. Kakaowa 7, 00-950 Warszawa" /></div>
          <Input label="IBAN" defaultValue="PL61 1090 1014 0000 0712 1981 2874" /><Input label="Format numeru faktury" defaultValue="FV/2026/00000" />
        </div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 'var(--space-5)' }}>
        <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
          <b style={{ color: 'var(--text-strong)' }}>Stawki VAT (OSS)</b>
          <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 10 }}>
            {[['Polska', '23'], ['Czechy', '21'], ['Niemcy', '19'], ['Ukraina (eksport)', '0']].map(([c, r]) => (
              <div key={c} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
                <span style={{ fontSize: 14, color: 'var(--text-body)' }}>{c}</span>
                <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <input defaultValue={r} inputMode="numeric" style={{ width: 58, textAlign: 'right', fontFamily: 'var(--font-mono)', fontSize: 14, color: 'var(--text-strong)', background: 'var(--surface-card)', border: 'none', outline: 'none', boxShadow: 'inset 0 0 0 1.5px var(--border-default)', borderRadius: 'var(--radius-sm)', padding: '7px 9px' }} />
                  <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, color: 'var(--text-muted)' }}>%</span>
                </span>
              </div>
            ))}
          </div>
          <a style={{ display: 'inline-block', marginTop: 12, fontSize: 13, color: 'var(--brand)', cursor: 'pointer' }}>Eksportuj podsumowanie OSS / JPK →</a>
        </div>
        <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
          <b style={{ color: 'var(--text-strong)' }}>Dostawa</b>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14, marginTop: 14 }}>
            <Input label="Stawka standardowa (PLN)" defaultValue="29" /><Input label="Próg darmowej dostawy (PLN)" defaultValue="800" />
          </div>
        </div>
      </div>
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <b style={{ color: 'var(--text-strong)' }}>Integracja KSeF (Krajowy System e-Faktur)</b>
          <Tag tone="origin" icon={<Icon name="check" size={12} />}>Połączono — aktywna sesja</Tag>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14, marginTop: 14 }}>
          <Select label="Środowisko" options={['Test (ksef-test.mf.gov.pl)', 'Production (ksef.mf.gov.pl)']} />
          <Input label="NIP wystawcy" defaultValue="5252525252" />
          <div style={{ gridColumn: '1/-1' }}><Input label="Token autoryzacyjny" type="password" defaultValue="••••••••••••••••••••" hint="Automatyczna wysyłka każdej wystawionej faktury · ostatnia wysyłka: FV/2026/00042 — dziś 14:32" /></div>
        </div>
      </div>
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <b style={{ color: 'var(--text-strong)' }}>E-paragony (HUB paragonowy)</b>
          <Tag tone="origin" icon={<Icon name="check" size={12} />}>Aktywne</Tag>
        </div>
        <p style={{ margin: '10px 0 0', fontSize: 13.5, lineHeight: 1.55, color: 'var(--text-muted)' }}>Sprzedaż B2C bez NIP otrzymuje automatycznie <b>e-paragon</b> zamiast faktury (zgodnie z ustawą o VAT). Wysyłka na e-mail lub numer telefonu klienta; zwroty przez korektę paragonu.</p>
      </div>
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <b style={{ color: 'var(--text-strong)' }}>SEO — Google friendly</b>
          <Tag tone="origin" icon={<Icon name="check" size={12} />}>Sitemap aktualna · Search Console połączono</Tag>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14, marginTop: 14 }}>
          <Input label="Szablon meta title" defaultValue="{produkt} — czekolada kuwertura | Mister Szoko" />
          <Input label="Domena" defaultValue="misterszoko.pl" />
          <div style={{ gridColumn: '1/-1' }}><Input label="Domyślny meta description" defaultValue="Czekolada kuwertura dla profesjonalistów — Callebaut, Valrhona, Cacao Barry. Worki 2,5–20 kg, ceny netto, dostawa 48 h." hint="Przyjazne adresy URL, sitemap.xml i dane strukturalne Product/Offer generowane automatycznie." /></div>
        </div>
      </div>
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <b style={{ color: 'var(--text-strong)' }}>Integracja InPost (Paczkomat® / ShipX)</b>
          <Tag tone="origin" icon={<Icon name="check" size={12} />}>Połączono — Geowidget aktywny</Tag>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14, marginTop: 14 }}>
          <Select label="Usługa" options={['Paczkomat 24/7', 'Kurier InPost']} />
          <Input label="Cena Paczkomat (PLN)" defaultValue="15,90" />
          <div style={{ gridColumn: '1/-1' }}><Input label="Token API ShipX" type="password" defaultValue="••••••••••••••••" hint="Etykiety i śledzenie generowane automatycznie · ostatnia paczka: FV/2026/00040 — dziś 11:05" /></div>
        </div>
      </div>
      <div><Button variant="accent">Zapisz</Button></div>
    </div>
  );
}

function Admin({ onExit }) {
  const [authed, setAuthed] = React.useState(false);
  const [tab, setTab] = React.useState('dash');
  if (!authed) return <AdminLogin onEnter={() => setAuthed(true)} onExit={onExit} />;
  const views = { dash: <Dash />, prod: <Products />, mag: <window.Magazyn />, orders: <Orders />, inv: <Invoices />, clients: <Clients />, settings: <Settings /> };
  return (
    <div style={{ minHeight: '100vh', display: 'flex', background: 'var(--bg-page-alt)' }}>
      <aside className="bo-side" style={{ width: 240, flex: 'none', background: 'var(--choco-900)', color: 'var(--cream-200)', padding: 'var(--space-5)', display: 'flex', flexDirection: 'column', gap: 4 }}>
        <img src={window.LOGO_SRC} alt="Mister Szoko" style={{ height: 52, margin: '4px 0 20px 8px', alignSelf: 'flex-start' }} />
        {NAV.map(([k, l, ic]) => {
          const on = tab === k;
          return <button key={k} onClick={() => setTab(k)} style={{ display: 'flex', alignItems: 'center', gap: 12, border: 'none', cursor: 'pointer', textAlign: 'left', padding: '11px 14px', borderRadius: 'var(--radius-md)', background: on ? 'rgba(255,255,255,0.10)' : 'transparent', color: on ? 'var(--cream-50)' : 'var(--choco-200)', fontFamily: 'var(--font-sans)', fontSize: 14.5, fontWeight: on ? 700 : 500 }}><Icon name={ic} size={18} /><span className="bo-lb">{l}</span></button>;
        })}
        <a onClick={onExit} style={{ marginTop: 'auto', display: 'flex', gap: 10, alignItems: 'center', padding: '11px 14px', fontSize: 13, color: 'var(--choco-300)', cursor: 'pointer' }}><Icon name="x" size={16} /><span className="bo-lb">Wyloguj</span></a>
      </aside>
      <main style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <div style={{ background: 'var(--surface-card)', borderBottom: '1px solid var(--border-subtle)', padding: '16px var(--space-7)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ fontFamily: 'var(--font-display)', fontSize: 22, color: 'var(--text-strong)' }}>{NAV.find((n) => n[0] === tab)[1]}</div>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', fontSize: 13, color: 'var(--text-muted)' }}><span style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--brand)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Icon name="user" size={17} /></span>admin@misterszoko.pl</div>
        </div>
        <div style={{ padding: 'var(--space-7)', overflowY: 'auto' }}>{views[tab]}</div>
      </main>
    </div>
  );
}

Object.assign(window, { Admin });
