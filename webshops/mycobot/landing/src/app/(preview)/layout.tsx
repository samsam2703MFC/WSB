import type { ReactNode } from 'react';
import type { Metadata } from 'next';
import '../globals.css';

/**
 * Root layout for the shareable theme page — a third root layout, in its own route
 * group, because it belongs to neither the 8-locale landing nor the French-only
 * console: it is a link you hand to one client.
 *
 * noindex: this is a customer's branding on a page anyone with the URL can open. It
 * carries no customer data, but it has no business in a search index either.
 */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

export default function PreviewRootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="fr" dir="ltr">
      <body style={{ margin: 0, background: 'var(--surface-page)' }}>{children}</body>
    </html>
  );
}
