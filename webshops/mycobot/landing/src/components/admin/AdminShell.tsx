'use client';

import React from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { Icon, IconButton, Badge, SidebarNav, type SidebarSection } from '@/design-system';

/**
 * The console chrome: the ink rail (248px, 68px collapsed) and the 60px topbar.
 * Navigation is real routing — each rail row is a route, so the browser's back
 * button and deep links work.
 */

export interface AdminNavCounts {
  tenants?: number;
  licenses?: number;
  sync?: number;
  modules?: number;
  messages?: number;
  profiles?: number;
  variables?: number;
  resources?: number;
}

/** Rail rows → routes. Order and grouping follow the design's ADMIN_NAV. */
export function buildNav(counts: AdminNavCounts): SidebarSection[] {
  return [
    {
      title: 'Clients',
      items: [{ value: '/admin/clients', label: 'Clients', icon: 'building-2', count: counts.profiles }],
    },
    {
      title: 'Facturation',
      items: [
        { value: '/admin', label: 'Tableau de bord', icon: 'layout-dashboard' },
        { value: '/admin/tenants', label: 'Tenants', icon: 'building', count: counts.tenants },
        { value: '/admin/licenses', label: 'Licences', icon: 'lock', count: counts.licenses },
        { value: '/admin/invoices', label: 'Factures', icon: 'receipt' },
        { value: '/admin/packages', label: 'Packages & tarifs', icon: 'tag' },
        { value: '/admin/sync', label: 'File de sync', icon: 'arrow-up-down', count: counts.sync },
        { value: '/admin/events', label: 'Événements', icon: 'clock' },
      ],
    },
    {
      title: 'Personnalisation',
      items: [
        { value: '/admin/registry', label: 'Registre', icon: 'list-checks', count: counts.variables },
        { value: '/admin/catalogue', label: 'Catalogue de données', icon: 'file-text', count: counts.resources },
      ],
    },
    {
      title: 'Contenu landing',
      items: [
        { value: '/admin/sections', label: 'Sections', icon: 'panels-top-left' },
        { value: '/admin/modules', label: 'Modules', icon: 'layers', count: counts.modules },
        { value: '/admin/brands', label: 'Enseignes', icon: 'store' },
        { value: '/admin/plans', label: 'Tarifs landing', icon: 'credit-card' },
        { value: '/admin/translations', label: 'Traductions', icon: 'languages' },
      ],
    },
    {
      title: 'Réseau',
      items: [{ value: '/admin/messages', label: 'Messages', icon: 'mail', count: counts.messages }],
    },
  ];
}

export interface AdminUser {
  email: string;
  role: string;
}

/**
 * Page title + breadcrumb per route. Derived here rather than passed down so each
 * screen file stays a plain server component with no chrome plumbing.
 */
const TITLES: Record<string, [string, string[]]> = {
  '/admin': ['Tableau de bord', ['TFB Admin', 'Facturation']],
  '/admin/tenants': ['Tenants', ['TFB Admin', 'Facturation', 'Tenants']],
  '/admin/licenses': ['Licences', ['TFB Admin', 'Facturation', 'Licences']],
  '/admin/invoices': ['Factures', ['TFB Admin', 'Facturation', 'Factures']],
  '/admin/packages': ['Packages & tarifs', ['TFB Admin', 'Facturation', 'Packages']],
  '/admin/sync': ['File de synchronisation', ['TFB Admin', 'Facturation', 'Sync']],
  '/admin/events': ['Événements de licence', ['TFB Admin', 'Facturation', 'Événements']],
  '/admin/clients': ['Clients', ['TFB Admin', 'Clients']],
  '/admin/registry': ['Registre des variables', ['TFB Admin', 'Personnalisation', 'Registre']],
  '/admin/catalogue': ['Catalogue de données', ['TFB Admin', 'Personnalisation', 'Catalogue']],
  '/admin/sections': ['Sections', ['TFB Admin', 'Contenu', 'Sections']],
  '/admin/modules': ['Modules', ['TFB Admin', 'Contenu', 'Modules']],
  '/admin/brands': ['Enseignes', ['TFB Admin', 'Contenu', 'Enseignes']],
  '/admin/plans': ['Tarifs landing', ['TFB Admin', 'Contenu', 'Tarifs']],
  '/admin/translations': ['Traductions', ['TFB Admin', 'Contenu', 'Traductions']],
  '/admin/messages': ['Messages', ['TFB Admin', 'Réseau', 'Messages']],
};

/** Detail routes inherit their parent's title; the screen renders its own heading. */
function titleFor(pathname: string): [string, string[]] {
  if (TITLES[pathname]) return TITLES[pathname];
  const parent = Object.keys(TITLES)
    .filter((p) => p !== '/admin' && pathname.startsWith(`${p}/`))
    .sort((a, b) => b.length - a.length)[0];
  if (parent) {
    const [, crumbs] = TITLES[parent]!;
    const id = pathname.slice(parent.length + 1);
    return [TITLES[parent]![0], [...crumbs, id]];
  }
  return ['TFB Admin', ['TFB Admin']];
}

export function AdminShell({
  user,
  counts,
  children,
}: {
  user: AdminUser;
  counts: AdminNavCounts;
  children: React.ReactNode;
}) {
  const router = useRouter();
  const pathname = usePathname() || '/admin';
  const sections = React.useMemo(() => buildNav(counts), [counts]);
  const [title, breadcrumb] = titleFor(pathname);

  // Longest matching prefix, so /admin/tenants/3 still highlights "Tenants" and
  // /admin does not match everything.
  const active = React.useMemo(() => {
    const values = sections.flatMap((s) => s.items.map((i) => i.value));
    return values
      .filter((v) => pathname === v || pathname.startsWith(`${v}/`))
      .sort((a, b) => b.length - a.length)[0] ?? '/admin';
  }, [pathname, sections]);

  const signOut = async () => {
    await fetch('/api/admin/auth/login', { method: 'DELETE' });
    router.replace('/admin/login');
  };

  const initials = user.email.slice(0, 2).toUpperCase();

  return (
    <div style={{ position: 'relative', display: 'flex', height: '100vh', minHeight: 0, background: 'var(--surface-page)', overflow: 'hidden' }}>
      <SidebarNav
        sections={sections}
        active={active}
        onSelect={(value) => router.push(value)}
        header={
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '4px 8px 14px' }}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/brand/logo-mark-inverse.png" alt="" width={28} height={28} />
            <div style={{ display: 'flex', flexDirection: 'column', minWidth: 0 }}>
              <span style={{ font: 'var(--weight-bold) var(--text-base)/1.05 var(--font-display)', color: '#fff' }}>TFB Admin</span>
              <span style={{ font: 'var(--type-caption)', color: 'rgba(255,255,255,0.5)' }}>Back office</span>
            </div>
          </div>
        }
        footer={
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, paddingTop: 12, borderTop: '1px solid var(--border-inverse)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: 10, borderRadius: 'var(--radius-md)', background: 'var(--alpha-white-08)' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 28, height: 28, flex: 'none', borderRadius: 'var(--radius-circle)', background: 'var(--plum-400)', color: '#fff', font: 'var(--weight-semibold) var(--text-xs)/1 var(--font-sans)' }}>
                {initials}
              </span>
              <div style={{ display: 'flex', flexDirection: 'column', minWidth: 0, flex: 1 }}>
                <span style={{ font: 'var(--weight-medium) var(--text-sm)/1.2 var(--font-sans)', color: '#fff', overflow: 'hidden', textOverflow: 'ellipsis' }}>{user.email}</span>
                <span style={{ font: 'var(--type-caption)', color: 'rgba(255,255,255,0.52)' }}>{user.role}</span>
              </div>
              <IconButton icon="log-out" label="Déconnexion" variant="inverse" size="sm" onClick={signOut} style={{ background: 'transparent', border: 0 }} />
            </div>
          </div>
        }
      />
      <div style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: 0 }}>
        <header style={{
          display: 'flex', alignItems: 'center', gap: 'var(--space-4)', height: 'var(--topbar-height)', flex: 'none',
          padding: '0 var(--space-6)', background: 'var(--surface-card)', borderBottom: '1px solid var(--border-default)',
        }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 1, minWidth: 0 }}>
            {breadcrumb && breadcrumb.length > 0 && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 4, font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                {breadcrumb.map((crumb, i) => (
                  <React.Fragment key={crumb}>
                    {i > 0 && <Icon name="chevron-right" size={11} className="fb-flip" />}
                    <span>{crumb}</span>
                  </React.Fragment>
                ))}
              </div>
            )}
            <h1 style={{ font: 'var(--weight-semibold) var(--text-xl)/1.2 var(--font-display)', letterSpacing: 'var(--tracking-tight)' }}>{title}</h1>
          </div>
          <div style={{ marginInlineStart: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
            {(counts.messages ?? 0) > 0 && <Badge tone="brand" size="sm">{counts.messages} non lus</Badge>}
            <IconButton icon="bell" label="Alertes" />
          </div>
        </header>
        <main style={{ flex: 1, minHeight: 0, overflow: 'auto', padding: 'var(--space-6)' }}>{children}</main>
      </div>
    </div>
  );
}
