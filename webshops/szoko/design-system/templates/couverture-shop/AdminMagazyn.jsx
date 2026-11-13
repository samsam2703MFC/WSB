const { Button, Icon, Input, Tag, Select } = window.MisterSzokoDesignSystem_613e75;
const { fmt } = window.MSlib;

const magCard = { background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-6)', boxShadow: 'var(--shadow-xs)' };
const magTh = { textAlign: 'left', padding: '10px 14px', fontFamily: 'var(--font-mono)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'var(--text-muted)', fontWeight: 600 };
const magTd = { padding: '11px 14px', fontSize: 13.5, color: 'var(--text-body)', borderTop: '1px solid var(--border-subtle)' };
function MagTable({ head, rows }) {
  return (
    <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-xs)', overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr style={{ background: 'var(--surface-sunken)' }}>{head.map((h) => <th key={h} style={magTh}>{h}</th>)}</tr></thead>
        <tbody>{rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j} style={magTd}>{c}</td>)}</tr>)}</tbody>
      </table>
    </div>
  );
}
const allVariants = () => window.MS.products.flatMap((p) => p.variants.map((v) => `${p.name} · ${v.label}`));

// ================= Edycja produktu =================
function ProductEditModal({ product: p, onClose }) {
  const [promo, setPromo] = React.useState(false);
  if (!p) return null;
  const slot = (label, big) => (
    <div style={{ aspectRatio: big ? '1/1' : '4/3', border: '1.5px dashed var(--border-default)', borderRadius: 8, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 6, color: 'var(--text-muted)', fontSize: 12, cursor: 'pointer', background: 'var(--bg-page)' }}>
      <Icon name="plus" size={18} />{label}
    </div>
  );
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'rgba(46,22,12,0.55)', backdropFilter: 'blur(3px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: 'var(--bg-page)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-xl)', width: 'min(760px, 100%)', maxHeight: '92vh', overflowY: 'auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '18px 28px', borderBottom: '1px solid var(--border-subtle)', position: 'sticky', top: 0, background: 'var(--bg-page)', zIndex: 1 }}>
          <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 21, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Edycja: {p.name}</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}><Icon name="x" size={20} /></button>
        </div>
        <div style={{ padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 20 }}>
          {/* zdjęcia */}
          <div>
            <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-strong)', marginBottom: 8 }}>Zdjęcia produktu</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 10 }}>
              <div style={{ aspectRatio: '4/3', borderRadius: 8, background: p.color, position: 'relative' }}><span style={{ position: 'absolute', bottom: 6, left: 8, fontFamily: 'var(--font-mono)', fontSize: 10, color: 'rgba(255,255,255,0.6)' }}>główne</span></div>
              <div style={{ aspectRatio: '4/3', borderRadius: 8, background: p.color, opacity: 0.6 }}></div>
              {slot('Dodaj zdjęcie')}
            </div>
          </div>
          {/* marka */}
          <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 14, alignItems: 'end' }}>
            <Select label="Marka" options={['Callebaut', 'Valrhona', 'Cacao Barry', 'Inna…']} defaultValue={p.brand} />
            <div>
              <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-strong)', marginBottom: 7 }}>Logo marki</div>
              {slot('Dodaj logo', false)}
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 14 }}>
            <Input label="Nazwa" defaultValue={p.name} />
            <Input label="Kakao" defaultValue={p.cacao} />
            <Select label="Kategoria" options={window.MS.categories.filter((c) => c.key !== 'all').map((c) => c.label)} />
          </div>
          {/* ceny per opakowanie */}
          <div>
            <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-strong)', marginBottom: 8 }}>Ceny netto per opakowanie</div>
            <MagTable head={['Opakowanie', 'Cena PLN', 'Cena EUR', 'Cena/kg']} rows={p.variants.map((v) => [
              <b style={{ color: 'var(--text-strong)' }}>{v.label}</b>,
              <input defaultValue={v.netto.PLN.toFixed(2)} style={{ width: 90, fontFamily: 'var(--font-mono)', fontSize: 13, padding: '7px 9px', border: '1px solid var(--border-default)', borderRadius: 6, outline: 'none' }} />,
              <input defaultValue={v.netto.EUR.toFixed(2)} style={{ width: 90, fontFamily: 'var(--font-mono)', fontSize: 13, padding: '7px 9px', border: '1px solid var(--border-default)', borderRadius: 6, outline: 'none' }} />,
              <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5, color: 'var(--caramel-600)' }}>{fmt('PLN', v.perKg.PLN)}/kg</span>,
            ])} />
          </div>
          {/* promocja */}
          <div style={{ border: '1px solid var(--border-subtle)', borderRadius: 10, padding: 16 }}>
            <label style={{ display: 'flex', gap: 10, alignItems: 'center', cursor: 'pointer' }}>
              <input type="checkbox" checked={promo} onChange={(e) => setPromo(e.target.checked)} style={{ width: 18, height: 18, accentColor: 'var(--brand)' }} />
              <b style={{ color: 'var(--text-strong)', fontSize: 14 }}>Promocja</b>
              {promo && <Tag tone="berry">−15% aktywna</Tag>}
            </label>
            {promo && (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12, marginTop: 14 }}>
                <Input label="Rabat %" defaultValue="15" />
                <Input label="Od" type="date" defaultValue="2026-07-21" />
                <Input label="Do" type="date" defaultValue="2026-08-04" />
                <Select label="Zakres" options={['Wszystkie opakowania', 'Worek 2,5 kg', 'Worek 10 kg', 'Karton 20 kg']} />
              </div>
            )}
          </div>
          {/* SEO */}
          <div style={{ border: '1px solid var(--border-subtle)', borderRadius: 10, padding: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <b style={{ color: 'var(--text-strong)', fontSize: 14 }}>SEO — Google friendly</b>
              <Tag tone="origin" icon={<Icon name="check" size={12} />}>Indeksowanie OK</Tag>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <Input label="Slug (adres URL)" defaultValue={'/sklep/' + p.id} hint="Krótki, z myślnikami, bez polskich znaków." />
              <Input label="Meta title" defaultValue={p.name + ' — czekolada kuwertura | Mister Szoko'} />
              <Input label="Meta description" defaultValue={p.blurb} hint="150–160 znaków — marka, % kakao, słowo kluczowe." />
              <div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>Podgląd w Google</div>
                <div style={{ background: 'var(--surface-card)', border: '1px solid var(--border-subtle)', borderRadius: 8, padding: 14 }}>
                  <div style={{ fontSize: 12, color: '#006621' }}>misterszoko.pl › sklep › {p.id}</div>
                  <div style={{ fontSize: 16, color: '#1a0dab', marginTop: 2 }}>{p.name} — czekolada kuwertura | Mister Szoko</div>
                  <div style={{ fontSize: 12.5, color: '#545454', marginTop: 2 }}>{p.blurb}</div>
                </div>
              </div>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', paddingBottom: 6 }}>
            <Button variant="ghost" onClick={onClose}>Anuluj</Button>
            <Button variant="accent" onClick={onClose} iconLeft={<Icon name="check" size={16} />}>Zapisz produkt</Button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ================= Magazyn =================
function Magazyn() {
  const [tab, setTab] = React.useState('stan');
  const [ins, setIns] = React.useState([
    { date: '18/07', prod: 'Ciemna 70% — Ghana · Karton 20 kg', qty: 40, price: '312,00 zł', sup: 'Callebaut Polska' },
    { date: '15/07', prod: 'Mleczna 33% · Worek 10 kg', qty: 60, price: '158,00 zł', sup: 'Callebaut Polska' },
    { date: '11/07', prod: 'Ruby RB1 · Worek 2,5 kg', qty: 24, price: '68,50 zł', sup: 'Barry Callebaut' },
  ]);
  const [cors, setCors] = React.useState([
    { date: '19/07', prod: 'Mleczna 33% · Worek 10 kg', qty: -1, reason: 'Uszkodzony worek', note: 'Worek rozdarty przy rozładunku', op: 'M. Nowak' },
    { date: '15/07', prod: 'Ruby RB1 · Worek 2,5 kg', qty: -2, reason: 'Próbka dla klienta', note: 'Degustacja — Horeca Kraków', op: 'A. Wiśniewska' },
  ]);
  const outs = [
    { date: '21/07', client: 'Cukiernia Demo', prod: 'Ciemna 70% — Ghana · Karton 20 kg', qty: 2, ref: 'FV/2026/00042' },
    { date: '21/07', client: 'Café Praha s.r.o.', prod: 'Ciemna 54% · Worek 10 kg', qty: 3, ref: 'FV/2026/00041' },
    { date: '20/07', client: 'Horeca Kraków', prod: 'Mleczna 33% · Worek 10 kg', qty: 8, ref: 'FV/2026/00040' },
    { date: '20/07', client: 'A. Kowalska', prod: 'Mleczna Karmel · Worek 2,5 kg', qty: 1, ref: 'PAR/2026/01204' },
  ];
  const [showReport, setShowReport] = React.useState(true);
  const [fi, setFi] = React.useState({ prod: '', qty: '', price: '', sup: '' });
  const [fc, setFc] = React.useState({ prod: '', qty: '', reason: 'Uszkodzony worek', note: '', op: '' });
  const inp = (v, on, ph, w) => <input value={v} onChange={on} placeholder={ph} style={{ width: w || '100%', fontFamily: 'var(--font-sans)', fontSize: 13.5, padding: '10px 11px', border: '1px solid var(--border-default)', borderRadius: 6, outline: 'none', background: 'var(--surface-card)' }} />;
  const selStyle = { fontFamily: 'var(--font-sans)', fontSize: 13.5, padding: '10px 11px', border: '1px solid var(--border-default)', borderRadius: 6, outline: 'none', background: 'var(--surface-card)', width: '100%' };
  const today = '21/07';

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
      {/* raport / druk */}
      <div style={{ ...magCard, display: 'flex', alignItems: 'flex-end', gap: 14, flexWrap: 'wrap' }} className="no-print">
        <div style={{ marginRight: 'auto' }}>
          <b style={{ color: 'var(--text-strong)', fontSize: 15 }}>Raport magazynowy</b>
          <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 3 }}>Miesiąc, rok lub dowolny zakres dat — do wydruku.</div>
        </div>
        <div><div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-strong)', marginBottom: 5 }}>Zakres</div>
          <select style={selStyle}><option>Ten miesiąc (lipiec 2026)</option><option>Ten rok (2026)</option><option>Własny zakres…</option></select></div>
        <div><div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-strong)', marginBottom: 5 }}>Od</div><input type="date" defaultValue="2026-07-01" style={selStyle} /></div>
        <div><div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-strong)', marginBottom: 5 }}>Do</div><input type="date" defaultValue="2026-07-21" style={selStyle} /></div>
        <Button variant="secondary" onClick={() => setShowReport(!showReport)}>{showReport ? 'Ukryj raport' : 'Pokaż raport'}</Button>
        <Button variant="primary" onClick={() => window.print()} iconLeft={<Icon name="arrowRight" size={15} />}>Drukuj raport</Button>
      </div>

      {showReport && (() => {
        const moves = [
          ...ins.map((r) => ({ date: r.date, type: 'Przyjęcie', prod: r.prod, q: +r.qty, det: r.sup, doc: 'PZ' })),
          ...outs.map((r) => ({ date: r.date, type: 'Wydanie', prod: r.prod, q: -r.qty, det: r.client, doc: r.ref })),
          ...cors.map((r) => ({ date: r.date, type: 'Korekta', prod: r.prod, q: r.qty, det: r.reason + ' — ' + r.note + ' (' + r.op + ')', doc: 'KOR' })),
        ].sort((a, b) => b.date.localeCompare(a.date));
        const sumIn = ins.reduce((s, r) => s + +r.qty, 0), sumOut = outs.reduce((s, r) => s + +r.qty, 0), sumCor = cors.reduce((s, r) => s + +r.qty, 0);
        const chip = (txt, col) => <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5, fontWeight: 600, color: col, background: 'var(--surface-sunken)', borderRadius: 999, padding: '6px 12px' }}>{txt}</span>;
        return (
          <div style={{ ...magCard }}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
              <b style={{ color: 'var(--text-strong)', fontSize: 15 }}>Raport ruchów magazynowych</b>
              <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>01.07 – 21.07.2026</span>
            </div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
              {chip('Przyjęcia +' + sumIn + ' szt.', 'var(--success)')}
              {chip('Wydania −' + sumOut + ' szt.', 'var(--berry-600)')}
              {chip('Korekty ' + (sumCor > 0 ? '+' : '') + sumCor + ' szt.', 'var(--caramel-600)')}
              {chip('Bilans ' + (sumIn - sumOut + sumCor > 0 ? '+' : '') + (sumIn - sumOut + sumCor) + ' szt.', 'var(--text-strong)')}
            </div>
            <MagTable head={['Data', 'Typ', 'Produkt · opakowanie', '±', 'Szczegóły', 'Dok.']} rows={moves.map((m) => [
              m.date,
              <Tag tone={m.type === 'Przyjęcie' ? 'origin' : m.type === 'Wydanie' ? 'berry' : 'accent'}>{m.type}</Tag>,
              <b style={{ color: 'var(--text-strong)' }}>{m.prod}</b>,
              <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 600, color: m.q < 0 ? 'var(--berry-600)' : 'var(--success)' }}>{m.q > 0 ? '+' : ''}{m.q} szt.</span>,
              <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{m.det}</span>,
              <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>{m.doc}</span>,
            ])} />
          </div>
        );
      })()}
      <div style={{ display: 'flex', gap: 8 }} className="no-print">
        {[['stan', 'Stan magazynowy'], ['in', 'Przyjęcia (stock in)'], ['out', 'Wydania (stock out)'], ['cor', 'Korekty']].map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} style={{ border: 'none', cursor: 'pointer', padding: '9px 16px', borderRadius: 6, fontFamily: 'var(--font-sans)', fontSize: 13.5, fontWeight: 700, background: tab === k ? 'var(--choco-800)' : 'var(--surface-card)', color: tab === k ? 'var(--cream-50)' : 'var(--text-body)', boxShadow: tab === k ? 'none' : 'inset 0 0 0 1px var(--border-default)' }}>{l}</button>
        ))}
      </div>

      {tab === 'stan' && (
        <MagTable head={['Produkt · opakowanie', 'Marka', 'Stan', 'Sprzedaż n−1', 'Prognoza (nast. mies.)', 'Zapas', 'Status']} rows={window.MS.products.flatMap((p) => p.variants.map((v) => {
          const noData = v.qty === 0;
          const sale = noData ? 5 : Math.max(3, Math.round(v.qty * 0.4));
          const weeks = sale > 0 ? Math.round((v.qty / (sale / 4.3)) * 10) / 10 : 0;
          const st = weeks < 2 ? ['Zamów', 'berry'] : weeks < 4 ? ['Nisko', 'accent'] : ['OK', 'origin'];
          return [
            <b style={{ color: 'var(--text-strong)' }}>{p.name} <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>· {v.label}</span></b>,
            <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{p.brand}</span>,
            <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 600, color: v.qty > 20 ? 'var(--success)' : v.qty > 0 ? 'var(--caramel-600)' : 'var(--danger)' }}>{v.qty} szt. <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>({v.qty * v.kg} kg)</span></span>,
            <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5 }}>{sale} szt. <span style={{ color: 'var(--text-muted)' }}>{noData ? '(ost. 6 tyg.)' : '(VI 2026)'}</span></span>,
            <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5 }}>~{sale} szt. / {sale * v.kg} kg</span>,
            <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5 }}>{weeks} tyg.</span>,
            <Tag tone={st[1]}>{st[0]}</Tag>,
          ];
        }))} />
      )}
      {tab === 'in' && (<>
        <div style={{ ...magCard }} className="no-print">
          <b style={{ color: 'var(--text-strong)', fontSize: 14.5 }}>Nowe przyjęcie</b>
          <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1.5fr auto', gap: 10, marginTop: 12, alignItems: 'center' }}>
            <select value={fi.prod} onChange={(e) => setFi({ ...fi, prod: e.target.value })} style={selStyle}><option value="">Produkt · opakowanie…</option>{allVariants().map((v) => <option key={v}>{v}</option>)}</select>
            {inp(fi.qty, (e) => setFi({ ...fi, qty: e.target.value }), 'Ilość (szt.)')}
            {inp(fi.price, (e) => setFi({ ...fi, price: e.target.value }), 'Cena zakupu/szt.')}
            {inp(fi.sup, (e) => setFi({ ...fi, sup: e.target.value }), 'Dostawca')}
            <Button variant="accent" size="sm" disabled={!fi.prod || !fi.qty} onClick={() => { setIns([{ date: today, prod: fi.prod, qty: +fi.qty, price: fi.price || '—', sup: fi.sup || '—' }, ...ins]); setFi({ prod: '', qty: '', price: '', sup: '' }); }}>Przyjmij</Button>
          </div>
        </div>
        <MagTable head={['Data', 'Produkt · opakowanie', 'Ilość', 'Cena zakupu/szt.', 'Dostawca']} rows={ins.map((r) => [r.date, <b style={{ color: 'var(--text-strong)' }}>{r.prod}</b>, <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--success)' }}>+{r.qty} szt.</span>, <span style={{ fontFamily: 'var(--font-mono)' }}>{r.price}</span>, r.sup])} />
      </>)}

      {tab === 'out' && (
        <MagTable head={['Data', 'Klient', 'Produkt · opakowanie', 'Ilość', 'Dokument']} rows={outs.map((r) => [r.date, <b style={{ color: 'var(--text-strong)' }}>{r.client}</b>, r.prod, <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--berry-600)' }}>−{r.qty} szt.</span>, <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5 }}>{r.ref}</span>])} />
      )}

      {tab === 'cor' && (<>
        <div style={{ ...magCard }} className="no-print">
          <b style={{ color: 'var(--text-strong)', fontSize: 14.5 }}>Korekta stanu (z uwagą operatora)</b>
          <div style={{ display: 'grid', gridTemplateColumns: '2fr 0.7fr 1.3fr', gap: 10, marginTop: 12 }}>
            <select value={fc.prod} onChange={(e) => setFc({ ...fc, prod: e.target.value })} style={selStyle}><option value="">Produkt · opakowanie…</option>{allVariants().map((v) => <option key={v}>{v}</option>)}</select>
            {inp(fc.qty, (e) => setFc({ ...fc, qty: e.target.value }), '± szt.')}
            <select value={fc.reason} onChange={(e) => setFc({ ...fc, reason: e.target.value })} style={selStyle}>{['Uszkodzony worek', 'Próbka dla klienta', 'Inwentaryzacja', 'Zwrot od klienta', 'Inne'].map((r) => <option key={r}>{r}</option>)}</select>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr auto', gap: 10, marginTop: 10, alignItems: 'center' }}>
            {inp(fc.note, (e) => setFc({ ...fc, note: e.target.value }), 'Uwaga operatora (np. worek rozdarty przy rozładunku)')}
            {inp(fc.op, (e) => setFc({ ...fc, op: e.target.value }), 'Operator')}
            <Button variant="accent" size="sm" disabled={!fc.prod || !fc.qty} onClick={() => { setCors([{ date: today, prod: fc.prod, qty: +fc.qty, reason: fc.reason, note: fc.note || '—', op: fc.op || '—' }, ...cors]); setFc({ prod: '', qty: '', reason: 'Uszkodzony worek', note: '', op: '' }); }}>Zapisz korektę</Button>
          </div>
        </div>
        <MagTable head={['Data', 'Produkt · opakowanie', '±', 'Powód', 'Uwaga operatora', 'Operator']} rows={cors.map((r) => [r.date, <b style={{ color: 'var(--text-strong)' }}>{r.prod}</b>, <span style={{ fontFamily: 'var(--font-mono)', color: r.qty < 0 ? 'var(--berry-600)' : 'var(--success)' }}>{r.qty > 0 ? '+' : ''}{r.qty} szt.</span>, <Tag tone={r.reason === 'Próbka dla klienta' ? 'accent' : 'plain'}>{r.reason}</Tag>, <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{r.note}</span>, r.op])} />
      </>)}
    </div>
  );
}

Object.assign(window, { Magazyn, ProductEditModal });
