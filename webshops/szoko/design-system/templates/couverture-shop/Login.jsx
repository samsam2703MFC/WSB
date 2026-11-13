const { Button, Input, Icon } = window.MisterSzokoDesignSystem_613e75;

function GoogleG({ size = 18 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 18 18" aria-hidden="true">
      <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
      <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
      <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
      <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
    </svg>
  );
}

function Login({ onBack, onLogin, onAdmin }) {
  const [tab, setTab] = React.useState('login');
  const [ctype, setCtype] = React.useState('b2b');
  return (
    <div style={{ minHeight: 'calc(100vh - 120px)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: 'var(--space-9) var(--space-6)' }}>
      <div style={{ width: 380 }}>
        <img src={window.LOGO_SRC} alt="Mister Szoko" style={{ height: 54, marginBottom: 22 }} />
        <div style={{ display: 'flex', gap: 22, borderBottom: '1px solid var(--border-subtle)', marginBottom: 24 }}>
          {[['login', 'Logowanie'], ['signup', 'Rejestracja']].map(([k, l]) => (
            <button key={k} onClick={() => setTab(k)} style={{ border: 'none', background: 'none', cursor: 'pointer', padding: '0 2px 12px', fontFamily: 'var(--font-sans)', fontSize: 15, fontWeight: 700, color: tab === k ? 'var(--text-strong)' : 'var(--text-muted)', borderBottom: tab === k ? '2px solid var(--choco-700)' : '2px solid transparent', marginBottom: -1 }}>{l}</button>
          ))}
        </div>
        <button onClick={onLogin} style={{ width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10, background: 'var(--surface-card)', border: '1px solid var(--border-default)', borderRadius: 6, padding: '12px 0', fontFamily: 'var(--font-sans)', fontSize: 14.5, fontWeight: 600, color: 'var(--text-strong)', cursor: 'pointer', marginBottom: 18 }}>
          <GoogleG /> Kontynuuj z Google
        </button>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, margin: '0 0 18px', color: 'var(--text-muted)', fontSize: 12.5 }}>
          <span style={{ flex: 1, height: 1, background: 'var(--border-subtle)' }} />lub<span style={{ flex: 1, height: 1, background: 'var(--border-subtle)' }} />
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {tab === 'signup' && (
            <div>
              <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-strong)', marginBottom: 7 }}>Typ konta</div>
              <div style={{ display: 'flex', border: '1px solid var(--border-default)', borderRadius: 6, overflow: 'hidden' }}>
                {[['b2c', 'B2C'], ['b2b', 'B2B'], ['hurt', 'Hurtownia']].map(([k, l], i) => (
                  <button key={k} onClick={() => setCtype(k)} style={{ flex: 1, border: 'none', borderLeft: i ? '1px solid var(--border-default)' : 'none', cursor: 'pointer', padding: '10px 0', fontFamily: 'var(--font-sans)', fontSize: 13.5, fontWeight: 600, background: ctype === k ? 'var(--choco-800)' : 'transparent', color: ctype === k ? 'var(--cream-50)' : 'var(--text-body)', transition: 'all var(--dur-fast) var(--ease-out)' }}>{l}</button>
                ))}
              </div>
            </div>
          )}
          {tab === 'signup' && ctype !== 'b2c' && <Input label={ctype === 'hurt' ? 'Nazwa hurtowni' : 'Nazwa firmy'} placeholder="Cukiernia…" />}
          {tab === 'signup' && ctype !== 'b2c' && <Input label="NIP / VAT UE" placeholder="PL1234567890" hint={ctype === 'hurt' ? 'Ceny hurtowe i opiekun handlowy po weryfikacji.' : 'Weryfikacja VIES przy pierwszym zamówieniu.'} />}
          {tab === 'signup' && <div style={{ fontSize: 13, lineHeight: 1.5, color: 'var(--text-body)', background: 'var(--surface-raised)', borderRadius: 6, padding: '10px 12px' }}>Kupujesz <b>ponad 40 kg miesięcznie</b>? Otrzymasz dostęp do <b>strefy pro</b>: rabaty lojalnościowe i zakup w 4 kliknięciach.</div>}
          <Input label="Email" placeholder="ty@pracownia.pl" />
          <Input label="Hasło" type="password" placeholder="••••••••" />
          {tab === 'login' && <a style={{ fontSize: 13, color: 'var(--brand)', cursor: 'pointer', alignSelf: 'flex-start' }}>Nie pamiętasz hasła?</a>}
          <Button variant="primary" block onClick={onLogin}>{tab === 'login' ? 'Zaloguj się' : 'Załóż konto'}</Button>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, color: 'var(--text-muted)', paddingTop: 6 }}>
            <a onClick={onBack} style={{ color: 'var(--text-muted)', cursor: 'pointer' }}>Kontynuuj jako gość</a>
            <a href="backoffice.html" style={{ color: 'var(--text-muted)', textDecoration: 'none' }}>Panel administratora</a>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { Login });
