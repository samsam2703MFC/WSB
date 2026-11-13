import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getModule } from '@/lib/landing/queries';
import { isLocale } from '@/lib/i18n/config';
import { LandingApp } from '@/components/landing/LandingApp';

/** Layout C — the module page tfb_modules.redirect_url points at. */
export const revalidate = 60;

type Params = { params: Promise<{ locale: string; slug: string }> };

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { locale, slug } = await params;
  if (!isLocale(locale)) return {};
  const found = await getModule(slug, locale);
  if (!found) return {};
  return {
    title: `${found.moduleRow.name} — The Franchise Buddy`,
    description: found.moduleRow.description,
  };
}

export default async function ModulePage({ params }: Params) {
  const { locale, slug } = await params;
  if (!isLocale(locale)) notFound();

  const found = await getModule(slug, locale);
  if (!found) notFound();

  return <LandingApp payload={found.payload} moduleKey={found.moduleRow.key} />;
}
