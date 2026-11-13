import { redirect } from 'next/navigation';
import { getSession } from '@/lib/auth/session';
import { LoginForm } from '@/components/admin/LoginForm';

/** The back office sign-in screen. Split panel: form on white, pitch on ink. */
export const dynamic = 'force-dynamic';

export default async function AdminLoginPage() {
  // Already signed in? Skip straight to the console.
  if (await getSession()) redirect('/admin');

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', height: '100vh', background: 'var(--surface-card)' }}>
      <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 'var(--space-6)', padding: '0 var(--space-16)', maxWidth: 540 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/brand/logo-mark.png" alt="" width={34} height={34} />
          <span style={{ font: 'var(--weight-bold) var(--text-lg)/1.05 var(--font-display)', letterSpacing: 'var(--tracking-tight)' }}>TFB Admin</span>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
          <h1 style={{ font: 'var(--type-heading-2)' }}>Back office</h1>
          <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>
            Facturation multi-tenant et contenu de la landing. Comptes <span className="fb-num">tfb_admin_users</span> · rôles superadmin / admin.
          </p>
        </div>
        <LoginForm />
      </div>
      <div style={{ background: 'var(--gradient-ink)', display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 'var(--space-6)', padding: 'var(--space-16)', color: 'var(--text-inverse)' }}>
        <span className="fb-eyebrow" style={{ color: 'var(--ember-300)' }}>Une base, deux services</span>
        <p style={{ font: 'var(--weight-semibold) var(--text-2xl)/1.32 var(--font-display)', letterSpacing: 'var(--tracking-tight)' }}>
          Les licences, les factures Stripe et la file de synchronisation d’un côté ; les sections, modules et huit langues de la landing de l’autre.
        </p>
        <div style={{ display: 'flex', gap: 'var(--space-8)' }}>
          {[['2', 'services'], ['3', 'packages'], ['8', 'langues actives']].map(([value, label]) => (
            <div key={label} style={{ display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 120 }}>
              <span className="fb-num" style={{ font: 'var(--weight-bold) var(--text-2xl)/1 var(--font-display)' }}>{value}</span>
              <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)' }}>{label}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
