import { prisma } from '@/lib/prisma';
import { DEFAULT_LOCALE } from './config';

/**
 * tfb_translations entity types in use.
 *
 * `ui` is a single pseudo-entity (entity_id = 0) whose `field` is a dotted key —
 * 'hero.title', 'nav.modules', 'group.finance'. It is how the landing keeps zero
 * hard-coded copy without inventing a table per section.
 */
export const UI_ENTITY = 'ui';
export const UI_ENTITY_ID = 0;

/** Multi-value fields (feature lists, explanation bullets) are stored pipe-joined. */
export const LIST_SEPARATOR = '|';

/**
 * Loads translations for a set of entities of one type, resolved per entity id.
 * Returns `id → field → value`, with the default locale already filled in behind
 * the requested locale.
 */
export async function loadEntityTranslations(
  entityType: string,
  entityIds: number[],
  locale: string,
): Promise<Map<number, Map<string, string>>> {
  const out = new Map<number, Map<string, string>>();
  if (entityIds.length === 0) return out;

  const codes = locale === DEFAULT_LOCALE ? [DEFAULT_LOCALE] : [locale, DEFAULT_LOCALE];
  const rows = await prisma.translation.findMany({
    where: { entityType, entityId: { in: entityIds }, languageCode: { in: codes } },
    select: { entityId: true, field: true, languageCode: true, value: true },
  });

  // Default locale first, then let the requested locale overwrite it — one pass,
  // and a blank requested value never wins over a present fallback.
  for (const r of rows) {
    if (r.languageCode !== DEFAULT_LOCALE) continue;
    if (!out.has(r.entityId)) out.set(r.entityId, new Map());
    out.get(r.entityId)!.set(r.field, r.value);
  }
  for (const r of rows) {
    if (r.languageCode === DEFAULT_LOCALE || r.value === '') continue;
    if (!out.has(r.entityId)) out.set(r.entityId, new Map());
    out.get(r.entityId)!.set(r.field, r.value);
  }
  return out;
}

/** Reads one field for one entity out of a loadEntityTranslations result. */
export function field(
  map: Map<number, Map<string, string>>,
  id: number,
  name: string,
  fallbackValue = '',
): string {
  return map.get(id)?.get(name) ?? fallbackValue;
}

/** Reads a pipe-joined field as a list. */
export function fieldList(map: Map<number, Map<string, string>>, id: number, name: string): string[] {
  const raw = field(map, id, name);
  return raw ? raw.split(LIST_SEPARATOR).filter(Boolean) : [];
}
