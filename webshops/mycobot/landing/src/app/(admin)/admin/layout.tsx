import type { ReactNode } from 'react';
import type { Metadata } from 'next';
import '../../globals.css';

/**
 * Root layout for the back office — a second root layout, in its own route group,
 * because the console is French-only LTR while the landing mirrors across 8 locales.
 *
 * The rail and the auth gate live in (console)/layout.tsx so /admin/login can
 * render without them.
 */
export const metadata: Metadata = {
  title: 'TFB Admin — Back office',
  robots: { index: false, follow: false },
  icons: { icon: '/brand/logo-mark.png' },
};

export default function AdminRootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="fr" dir="ltr" style={{ height: '100%' }}>
      <body style={{ margin: 0, height: '100%', background: 'var(--surface-page)' }}>{children}</body>
    </html>
  );
}
