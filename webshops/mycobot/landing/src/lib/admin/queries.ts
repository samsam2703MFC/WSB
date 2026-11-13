import { prisma } from '@/lib/prisma';
import { DEFAULT_LOCALE } from '@/lib/i18n/config';
import type { EditorField, EditorLanguage } from '@/components/admin/TranslationEditor';

/** Shared server-side reads for the back office's content screens. */

const DATE = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
const DATETIME = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

export function formatDate(value: Date): string {
  return DATE.format(value);
}

export function formatDateTime(value: Date): string {
  return DATETIME.format(value);
}

export async function getEditorLanguages(): Promise<EditorLanguage[]> {
  const rows = await prisma.language.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } });
  return rows.map((l) => ({ code: l.code, name: l.name, isRtl: l.isRtl, isDefault: l.isDefault }));
}

/**
 * Every translatable field for one entity, as a dense grid: each field carries a
 * value for every active language, '' where no row exists.
 *
 * `seedFields` guarantees the editor shows a row even before any translation has
 * been written — otherwise a brand-new module would render an empty table with
 * nothing to type into.
 */
export async function getTranslationGrid(
  entityType: string,
  entityId: number,
  languages: EditorLanguage[],
  seedFields: string[] = [],
): Promise<EditorField[]> {
  const rows = await prisma.translation.findMany({
    where: { entityType, entityId },
    orderBy: { field: 'asc' },
  });

  const byField = new Map<string, Record<string, string>>();
  for (const f of seedFields) byField.set(f, {});
  for (const r of rows) {
    if (!byField.has(r.field)) byField.set(r.field, {});
    byField.get(r.field)![r.languageCode] = r.value;
  }

  return [...byField.entries()].map(([field, values]) => ({
    field,
    values: Object.fromEntries(languages.map((l) => [l.code, values[l.code] ?? ''])),
  }));
}

/** Fields the seed writes per entity type — the editor's starting rows. */
export const SEED_FIELDS: Record<string, string[]> = {
  module: ['name', 'description', 'bullets', 'metric_value', 'metric_label'],
  plan: ['name', 'description', 'features'],
  brand: ['name'],
  section: ['title', 'subtitle'],
  screenshot: ['alt'],
  ui: [],
};

/** Resolves a module's display name for a heading, falling back to its key. */
export async function moduleDisplayName(moduleId: number, moduleKey: string): Promise<string> {
  const row = await prisma.translation.findFirst({
    where: { entityType: 'module', entityId: moduleId, field: 'name', languageCode: DEFAULT_LOCALE },
  });
  return row?.value || moduleKey;
}
