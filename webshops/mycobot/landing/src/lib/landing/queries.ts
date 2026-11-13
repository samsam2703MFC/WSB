import { prisma } from '@/lib/prisma';
import { DEFAULT_LOCALE, dirFor, formatMoney } from '@/lib/i18n/config';
import { UI_ENTITY, UI_ENTITY_ID, LIST_SEPARATOR, loadEntityTranslations, field, fieldList } from '@/lib/i18n/translations';
import { storageUrl } from '@/lib/storage';
import type { LandingPayload, LandingModule, LandingPlan } from './types';

/**
 * Builds the whole landing payload for one locale. Nothing on the landing is
 * hard-coded: sections, modules, plans, brands and every string come from here.
 */
export async function getLanding(locale: string): Promise<LandingPayload> {
  const codes = locale === DEFAULT_LOCALE ? [DEFAULT_LOCALE] : [locale, DEFAULT_LOCALE];

  const [languages, sections, brands, modules, plans, uiRows] = await Promise.all([
    prisma.language.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } }),
    prisma.section.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } }),
    prisma.brand.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } }),
    prisma.module.findMany({
      where: { isActive: true },
      orderBy: { sortOrder: 'asc' },
      include: {
        screenshots: { orderBy: { sortOrder: 'asc' } },
        features: { where: { isActive: true }, orderBy: { sortOrder: 'asc' } },
      },
    }),
    prisma.plan.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } }),
    prisma.translation.findMany({
      where: { entityType: UI_ENTITY, entityId: UI_ENTITY_ID, languageCode: { in: codes } },
      select: { field: true, languageCode: true, value: true },
    }),
  ]);

  // ui strings: default locale first, requested locale overwrites non-empty values.
  const strings: Record<string, string> = {};
  for (const r of uiRows) if (r.languageCode === DEFAULT_LOCALE) strings[r.field] = r.value;
  for (const r of uiRows) if (r.languageCode !== DEFAULT_LOCALE && r.value !== '') strings[r.field] = r.value;

  const moduleIds = modules.map((m) => m.id);
  const screenshotIds = modules.flatMap((m) => m.screenshots.map((s) => s.id));
  const featureIds = modules.flatMap((m) => m.features.map((f) => f.id));
  const [moduleT, planT, brandT, shotT, featureT] = await Promise.all([
    loadEntityTranslations('module', moduleIds, locale),
    loadEntityTranslations('plan', plans.map((p) => p.id), locale),
    loadEntityTranslations('brand', brands.map((b) => b.id), locale),
    loadEntityTranslations('screenshot', screenshotIds, locale),
    loadEntityTranslations('feature', featureIds, locale),
  ]);

  const landingModules: LandingModule[] = modules.map((m) => {
    const groupKey = m.moduleGroup ?? '';
    const shotOf = (s: (typeof m.screenshots)[number], i: number) => ({
      src: storageUrl(s.filePath),
      alt: field(shotT, s.id, 'alt') || `${strings['shot.alt'] ?? 'Capture'} — ${m.key} ${i + 1}`,
    });
    return {
      id: m.id,
      key: m.key,
      slug: m.slug,
      icon: m.icon ?? 'layers',
      groupKey,
      // Group captions are translated through the ui dictionary: group.<lowercased value>.
      group: strings[`group.${groupKey.toLowerCase()}`] ?? groupKey,
      name: field(moduleT, m.id, 'name', m.key),
      description: field(moduleT, m.id, 'description'),
      overview: field(moduleT, m.id, 'overview'),
      isNew: m.isNew,
      redirectUrl: m.redirectUrl,
      repo: m.repo,
      // A frame attached to a function belongs to that function, not to the
      // module carousel — otherwise every shot would show up twice.
      screenshots: m.screenshots.filter((s) => s.featureId === null).map(shotOf),
      features: m.features.map((f) => ({
        id: f.id,
        key: f.key,
        icon: f.icon ?? 'circle-check',
        name: field(featureT, f.id, 'name', f.key),
        description: field(featureT, f.id, 'description'),
        screenshots: m.screenshots.filter((s) => s.featureId === f.id).map(shotOf),
      })),
      bullets: fieldList(moduleT, m.id, 'bullets'),
      metric: [field(moduleT, m.id, 'metric_value', '—'), field(moduleT, m.id, 'metric_label')],
    };
  });

  const landingPlans: LandingPlan[] = plans.map((p) => ({
    id: p.id,
    key: p.key,
    name: field(planT, p.id, 'name', p.key),
    description: field(planT, p.id, 'description'),
    features: fieldList(planT, p.id, 'features'),
    priceLabel: formatMoney(p.amount, p.currency, locale),
    amount: p.amount,
    currency: p.currency,
    interval: p.interval,
    featured: p.isFeatured,
    stripePriceId: p.stripePriceId,
  }));

  return {
    locale,
    dir: dirFor(locale),
    languages: languages.map((l) => ({ code: l.code, name: l.name, rtl: l.isRtl })),
    sections: sections.map((s) => ({ key: s.key, type: s.type, sortOrder: s.sortOrder })),
    brands: brands.map((b) => ({
      slug: b.slug,
      name: field(brandT, b.id, 'name', b.name),
      logoPath: storageUrl(b.logoPath),
      websiteUrl: b.websiteUrl,
    })),
    modules: landingModules,
    plans: landingPlans,
    strings,
  };
}

/** One module resolved for the module detail page (layout C). */
export async function getModule(slug: string, locale: string): Promise<{ payload: LandingPayload; moduleRow: LandingModule } | null> {
  const payload = await getLanding(locale);
  const moduleRow = payload.modules.find((m) => m.slug === slug || m.key === slug);
  return moduleRow ? { payload, moduleRow } : null;
}

/** Active locales for the language switcher, used by GET /api/languages. */
export async function getLanguages() {
  const rows = await prisma.language.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } });
  return rows.map((l) => ({ code: l.code, name: l.name, rtl: l.isRtl, isDefault: l.isDefault }));
}

export { LIST_SEPARATOR };
