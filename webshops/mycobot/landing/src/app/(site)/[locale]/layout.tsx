import type { ReactNode } from 'react';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import '../../globals.css';
import { LOCALES, dirFor, isLocale } from '@/lib/i18n/config';

/**
 * Root layout for the public landing. Arabic is a full mirror driven by one
 * attribute: dir="rtl" here, and logical properties everywhere downstream.
 */

/**
 * No generateStaticParams: the landing is entirely DB-driven, so prerendering all 8
 * locales would make every build depend on a reachable MySQL. Each locale is
 * rendered on first request and then ISR-cached (see `revalidate` on the pages) —
 * the copy is still in the HTML for crawlers.
 */
export const dynamicParams = true;

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale } = await params;
  return {
    title: 'The Franchise Buddy — ERP SaaS pour franchises',
    description:
      'ERP tout-en-un pour les franchises et chaînes de magasins en restauration et retail : shop, facturation, offres, scan, marketing, CEObot et PWA.',
    alternates: {
      canonical: `/${locale}`,
      languages: Object.fromEntries(LOCALES.map((l) => [l, `/${l}`])),
    },
    icons: { icon: '/brand/logo-mark.png' },
  };
}

export default async function SiteLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();

  return (
    <html lang={locale} dir={dirFor(locale)}>
      <body style={{ margin: 0, background: 'var(--surface-card)' }}>{children}</body>
    </html>
  );
}
