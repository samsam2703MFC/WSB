/**
 * Where the per-tenant overlay lives: tfb_settings (key VARCHAR(120) PK, value JSON),
 * which the init migration already created and nothing else uses.
 *
 * No migration, no column per client, no table per client. Three namespaced keys:
 *
 *   global.published          the house defaults, above the registry's
 *   tenant.<slug>.published   what the PWA is being served right now
 *   tenant.<slug>.draft       what the console is editing
 *
 * An absent entry means "inherit". Clearing a field DELETES it rather than storing
 * an empty value — the same rule tfb_translations already lives by, because an
 * absent row is what the fallback keys off.
 */

import { prisma } from '@/lib/prisma';
import { validate, validateTheme, variable, type ConfigValue, type ConfigValues } from './registry';

export interface Overlay {
  values: ConfigValues;
  /** Bumped on publish. This is what the PWA's service worker watches. */
  version: number;
  updatedAt: string | null;
}

const EMPTY: Overlay = { values: {}, version: 0, updatedAt: null };

/** Slugs are used to build setting keys, so they are constrained before that. */
const SLUG = /^[a-z0-9][a-z0-9-]{0,60}$/;

export function isSlug(value: string): boolean {
  return SLUG.test(value);
}

function publishedKey(slug: string) {
  return `tenant.${slug}.published`;
}
function draftKey(slug: string) {
  return `tenant.${slug}.draft`;
}
const GLOBAL_KEY = 'global.published';

/**
 * Reads one setting row. A row whose JSON does not match the shape is treated as
 * absent rather than thrown: a malformed overlay must degrade to inheritance, not
 * take a client's app down.
 */
async function read(key: string): Promise<Overlay> {
  const row = await prisma.setting.findUnique({ where: { key } });
  if (!row) return EMPTY;
  const value = row.value as unknown;
  if (!value || typeof value !== 'object' || Array.isArray(value)) return EMPTY;
  const record = value as Record<string, unknown>;
  const values = record.values;
  if (!values || typeof values !== 'object' || Array.isArray(values)) return EMPTY;
  return {
    values: values as ConfigValues,
    version: typeof record.version === 'number' ? record.version : 0,
    updatedAt: typeof record.updatedAt === 'string' ? record.updatedAt : null,
  };
}

async function write(key: string, overlay: Overlay): Promise<void> {
  const value = { values: overlay.values, version: overlay.version, updatedAt: overlay.updatedAt };
  await prisma.setting.upsert({
    where: { key },
    create: { key, value: value as never },
    update: { value: value as never },
  });
}

export function globalOverlay(): Promise<Overlay> {
  return read(GLOBAL_KEY);
}

export function publishedOverlay(slug: string): Promise<Overlay> {
  return read(publishedKey(slug));
}

/** The draft falls back to the published set the first time a tenant is opened. */
export async function draftOverlay(slug: string): Promise<Overlay> {
  const draft = await read(draftKey(slug));
  if (draft.updatedAt !== null || Object.keys(draft.values).length > 0) return draft;
  const published = await read(publishedKey(slug));
  return { values: published.values, version: published.version, updatedAt: null };
}

export interface WriteResult {
  ok: boolean;
  /** Per-key validation messages; empty when the write went through. */
  errors: Record<string, string>;
  draft?: Overlay;
}

/**
 * Applies a patch to a tenant's draft. `null` removes the override, which is how the
 * console's "Réinitialiser" works — it must delete the entry, not store a blank.
 *
 * Every value is validated against the registry here. This is the only door, so an
 * invalid value cannot reach the database and therefore cannot reach a PWA.
 */
export async function patchDraft(slug: string, patch: Record<string, ConfigValue>): Promise<WriteResult> {
  if (!isSlug(slug)) return { ok: false, errors: { _: 'Slug de tenant invalide.' } };

  const errors: Record<string, string> = {};
  for (const [key, value] of Object.entries(patch)) {
    if (!variable(key)) {
      errors[key] = `Variable absente du registre : ${key}.`;
      continue;
    }
    if (value === null) continue; // a removal needs no validation
    const message = validate(key, value);
    if (message) errors[key] = message;
  }
  if (Object.keys(errors).length > 0) return { ok: false, errors };

  const current = await draftOverlay(slug);
  const values: ConfigValues = { ...current.values };
  for (const [key, value] of Object.entries(patch)) {
    if (value === null) delete values[key];
    else values[key] = value;
  }

  const draft: Overlay = { values, version: current.version, updatedAt: new Date().toISOString() };
  await write(draftKey(slug), draft);
  return { ok: true, errors: {}, draft };
}

/**
 * Publishes the draft: it becomes what /api/app-config serves, and `version` is
 * incremented so the service worker knows its cached copy is stale.
 *
 * The draft is re-validated first. It was validated on write, but the registry may
 * have tightened since — publishing is the last point where refusing is still cheap.
 */
export interface PublicationEntry {
  version: number;
  at: string;
  by: string;
  /** Keys whose published value changed — what a reviewer actually needs to see. */
  changed: string[];
}

const HISTORY_LIMIT = 20;

function historyKey(slug: string) {
  return `tenant.${slug}.history`;
}

/** Publication history, newest first. Empty until the first publish. */
export async function publicationHistory(slug: string): Promise<PublicationEntry[]> {
  if (!isSlug(slug)) return [];
  const row = await prisma.setting.findUnique({ where: { key: historyKey(slug) } });
  const value = row?.value as { items?: PublicationEntry[] } | null;
  return Array.isArray(value?.items) ? value.items : [];
}

/** Keys that differ between two published sets, in either direction. */
function changedKeys(before: ConfigValues, after: ConfigValues): string[] {
  const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
  return [...keys].filter((k) => JSON.stringify(before[k]) !== JSON.stringify(after[k])).sort();
}

export async function publish(slug: string, actor = 'inconnu'): Promise<WriteResult & { version?: number }> {
  if (!isSlug(slug)) return { ok: false, errors: { _: 'Slug de tenant invalide.' } };

  const [draft, global] = await Promise.all([draftOverlay(slug), globalOverlay()]);

  const errors: Record<string, string> = {};
  for (const [key, value] of Object.entries(draft.values)) {
    const message = variable(key) ? validate(key, value) : `Variable absente du registre : ${key}.`;
    if (message) errors[key] = message;
  }
  // Contrast is a property of a pair, so it is advisory while editing — you have to be
  // able to set a light primary and *then* switch its ink — and blocking here, which
  // is the last point where refusing costs nothing.
  const resolved: ConfigValues = { ...global.values, ...draft.values };
  for (const [key, message] of Object.entries(validateTheme(resolved))) errors[key] ??= message;

  if (Object.keys(errors).length > 0) return { ok: false, errors };

  const published = await read(publishedKey(slug));
  const version = published.version + 1;
  const now = new Date().toISOString();

  // The history is written with the publication, not after it: an entry that can go
  // missing is worse than no history at all, because it makes the log look complete.
  const entry: PublicationEntry = {
    version,
    at: now,
    by: actor,
    changed: changedKeys(published.values, draft.values),
  };
  const history = [entry, ...(await publicationHistory(slug))].slice(0, HISTORY_LIMIT);

  await write(publishedKey(slug), { values: draft.values, version, updatedAt: now });
  await write(draftKey(slug), { values: draft.values, version, updatedAt: null });
  await prisma.setting.upsert({
    where: { key: historyKey(slug) },
    create: { key: historyKey(slug), value: { items: history } as never },
    update: { value: { items: history } as never },
  });

  return { ok: true, errors: {}, version };
}

/** Number of keys a tenant overrides, for the console's list screen. */
export async function overrideCounts(): Promise<Map<string, { overrides: number; version: number; dirty: boolean }>> {
  const rows = await prisma.setting.findMany({ where: { key: { startsWith: 'tenant.' } } });
  const out = new Map<string, { overrides: number; version: number; dirty: boolean }>();

  for (const row of rows) {
    const match = /^tenant\.([a-z0-9-]+)\.(published|draft)$/.exec(row.key);
    if (!match) continue;
    const [, slug, kind] = match as unknown as [string, string, 'published' | 'draft'];
    const value = row.value as { values?: ConfigValues; version?: number; updatedAt?: string | null } | null;
    const entry = out.get(slug) ?? { overrides: 0, version: 0, dirty: false };
    if (kind === 'published') {
      entry.overrides = Object.keys(value?.values ?? {}).length;
      entry.version = typeof value?.version === 'number' ? value.version : 0;
    } else if (value?.updatedAt) {
      entry.dirty = true;
    }
    out.set(slug, entry);
  }
  return out;
}

/** Which tenants override a given catalogue resource — the console's "consommée par". */
export async function consumersOfResource(resourceKey: string): Promise<string[]> {
  const rows = await prisma.setting.findMany({ where: { key: { startsWith: 'tenant.' } } });
  const slugs = new Set<string>();
  for (const row of rows) {
    const match = /^tenant\.([a-z0-9-]+)\.published$/.exec(row.key);
    if (!match) continue;
    const values = (row.value as { values?: ConfigValues } | null)?.values ?? {};
    if (Object.values(values).includes(resourceKey)) slugs.add(match[1]!);
  }
  return [...slugs].sort();
}
