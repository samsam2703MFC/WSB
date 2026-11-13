import { notFound } from 'next/navigation';
import { getLanding } from '@/lib/landing/queries';
import { isLocale } from '@/lib/i18n/config';
import { LandingApp } from '@/components/landing/LandingApp';

/**
 * The commercial landing. Rendered on the server so the copy is in the HTML for
 * SEO (prompt_technique_tfb.md §4), then hydrated for the filters, carousels and
 * forms.
 */
export const revalidate = 60;

export default async function LandingPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();

  const payload = await getLanding(locale);
  return <LandingApp payload={payload} />;
}
