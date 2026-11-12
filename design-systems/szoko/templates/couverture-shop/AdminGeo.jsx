const { Tag } = window.MisterSzokoDesignSystem_613e75;

const GEO = [
  { country: 'Polska', region: 'Mazowieckie', city: 'Warszawa', lat: 52.23, lng: 21.01, n: 12, orders: 96, rev: '96 400 zł' },
  { country: 'Polska', region: 'Małopolskie', city: 'Kraków', lat: 50.06, lng: 19.94, n: 8, orders: 71, rev: '74 200 zł' },
  { country: 'Polska', region: 'Pomorskie', city: 'Gdańsk', lat: 54.35, lng: 18.65, n: 5, orders: 34, rev: '31 800 zł' },
  { country: 'Polska', region: 'Dolnośląskie', city: 'Wrocław', lat: 51.11, lng: 17.03, n: 4, orders: 26, rev: '22 400 zł' },
  { country: 'Polska', region: 'Wielkopolskie', city: 'Poznań', lat: 52.41, lng: 16.93, n: 3, orders: 19, rev: '18 900 zł' },
  { country: 'Czechy', region: 'Praga', city: 'Praha', lat: 50.08, lng: 14.44, n: 2, orders: 14, rev: '42 300 zł' },
  { country: 'Niemcy', region: 'Bawaria', city: 'Monachium', lat: 48.14, lng: 11.58, n: 2, orders: 21, rev: '134 200 zł' },
];

function GeoSection() {
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (!ref.current || !window.L) return;
    const map = window.L.map(ref.current, { scrollWheelZoom: false }).setView([51.2, 16.8], 5);
    window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
    GEO.forEach((g) => {
      window.L.circleMarker([g.lat, g.lng], { radius: 6 + g.n * 1.1, color: '#41281A', weight: 1.5, fillColor: '#C68A3C', fillOpacity: 0.78 })
        .addTo(map).bindPopup('<b>' + g.city + '</b><br>' + g.n + ' klientów · ' + g.rev + ' / rok');
    });
    const t = setTimeout(() => map.invalidateSize(), 250);
    return () => { clearTimeout(t); map.remove(); };
  }, []);
  const th = { textAlign: 'left', padding: '10px 14px', fontFamily: 'var(--font-mono)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'var(--text-muted)', fontWeight: 600 };
  const td = { padding: '11px 14px', fontSize: 13.5, color: 'var(--text-body)', borderTop: '1px solid var(--border-subtle)' };
  const totalN = GEO.reduce((s, g) => s + g.n, 0);
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', marginBottom: 'var(--space-6)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 20, margin: 0, color: 'var(--text-strong)', fontWeight: 600 }}>Analiza geograficzna klientów</h3>
        <Tag tone="plain">{totalN} klientów · 3 kraje</Tag>
      </div>
      <div ref={ref} style={{ height: '48vh', minHeight: 320, borderRadius: 12, overflow: 'hidden', boxShadow: 'var(--shadow-sm)', position: 'relative', zIndex: 0 }} />
      <div style={{ background: 'var(--surface-card)', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-xs)', overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr style={{ background: 'var(--surface-sunken)' }}>{['Kraj', 'Region', 'Miasto', 'Klienci', 'Zamówienia / rok', 'Obrót / rok'].map((h) => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {GEO.map((g) => (
              <tr key={g.city}>
                <td style={td}><b style={{ color: 'var(--text-strong)' }}>{g.country}</b></td>
                <td style={td}>{g.region}</td>
                <td style={td}>{g.city}</td>
                <td style={td}><span style={{ fontFamily: 'var(--font-mono)' }}>{g.n}</span></td>
                <td style={td}><span style={{ fontFamily: 'var(--font-mono)' }}>{g.orders}</span></td>
                <td style={td}><span style={{ fontFamily: 'var(--font-mono)', fontWeight: 600, color: 'var(--text-strong)' }}>{g.rev}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

Object.assign(window, { GeoSection });
