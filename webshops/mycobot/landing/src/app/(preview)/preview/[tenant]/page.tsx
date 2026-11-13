import { promises as fs } from 'node:fs';
import path from 'node:path';
import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import { getSession } from '@/lib/auth/session';
import { getBillingSnapshot } from '@/lib/billing/client';
import { isSlug, draftOverlay, globalOverlay, publishedOverlay } from '@/lib/config/store';
import { resolveAll } from '@/lib/config/resolve';
import { themeCss, themeAssets } from '@/lib/config/theme';
import { registryDefaults, type ConfigValues } from '@/lib/config/registry';
import { ThemePreview } from '@/components/preview/ThemePreview';

type Params = { params: Promise<{ tenant: string }>; searchParams: Promise<{ draft?: string }> };

/**
 * GET /preview/<slug> — one client's whole interface, on one page.
 *
 * Every component the product is built from, rendered with that client's resolved
 * theme: buttons, dropdowns, fields, cards, tables, feedback and the full icon set.
 * The link is meant to be handed to the customer, so it needs no session — it shows
 * branding, never their data.
 *
 * `?draft=1` renders what the console is editing rather than what is published, and
 * that one does require an admin session.
 */
export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const slug = (await params).tenant;
  if (!isSlug(slug)) return { title: 'Thème' };

  const snapshot = await getBillingSnapshot();
  const tenant = snapshot.tenants.find((t) => t.slug === slug);
  const [overlay, global] = await Promise.all([publishedOverlay(slug), globalOverlay()]);
  const values = resolvedValues(overlay.values, global.values);
  const assets = themeAssets(values);

  return {
    title: `${tenant?.name ?? slug} — thème`,
    robots: { index: false, follow: false },
    ...(assets.favicon ? { icons: { icon: assets.favicon } } : {}),
  };
}

/** Tenant over global over registry, flattened for the theme generator. */
function resolvedValues(tenantValues: ConfigValues, globalValues: ConfigValues): ConfigValues {
  return { ...registryDefaults(), ...globalValues, ...tenantValues };
}

export default async function PreviewPage({ params, searchParams }: Params) {
  const slug = (await params).tenant;
  if (!isSlug(slug)) notFound();

  const wantsDraft = (await searchParams).draft === '1';
  if (wantsDraft && !(await getSession())) notFound();

  const [snapshot, overlay, global, icons] = await Promise.all([
    getBillingSnapshot(),
    wantsDraft ? draftOverlay(slug) : publishedOverlay(slug),
    globalOverlay(),
    listIcons(),
  ]);

  const tenant = snapshot.tenants.find((t) => t.slug === slug);
  if (!tenant) notFound();

  const values = resolvedValues(overlay.values, global.values);

  return (
    <ThemePreview
      tenantName={tenant.name}
      slug={slug}
      version={overlay.version}
      draft={wantsDraft}
      updatedAt={overlay.updatedAt}
      css={themeCss(values)}
      assets={themeAssets(values)}
      // The console shows keys; the client-facing page shows the values themselves.
      swatches={swatchesFrom(values)}
      overrides={Object.keys(overlay.values).length}
      icons={icons}
      resolutions={resolveAll(overlay, global).map((r) => ({ key: r.key, from: r.from }))}
    />
  );
}

/** The 75 vendored glyphs, read from disk so the page cannot drift from the folder. */
async function listIcons(): Promise<string[]> {
  try {
    const dir = path.join(process.cwd(), 'public', 'icons');
    const files = await fs.readdir(dir);
    return files.filter((f) => f.endsWith('.svg')).map((f) => f.replace(/\.svg$/, '')).sort();
  } catch {
    return [];
  }
}

function swatchesFrom(values: ConfigValues): { key: string; label: string; value: string }[] {
  const entries: [string, string][] = [
    ['theme.primary', 'Primaire'],
    ['theme.accent', 'Accent'],
    ['theme.surface_page', 'Fond de page'],
    ['theme.surface_card', 'Fond des cartes'],
    ['theme.text_primary', 'Texte principal'],
    ['theme.text_secondary', 'Texte secondaire'],
    ['theme.border', 'Bordures'],
    ['status.success', 'Succès'],
    ['status.warning', 'Avertissement'],
    ['status.danger', 'Erreur'],
    ['status.info', 'Information'],
  ];
  return entries
    .map(([key, label]) => ({ key, label, value: typeof values[key] === 'string' ? (values[key] as string) : '' }))
    .filter((s) => s.value);
}
