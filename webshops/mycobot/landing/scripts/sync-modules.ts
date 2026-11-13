/**
 * Pulls every module repository's `.tfb/module.json` into the landing database, so
 * the landing tracks what the products actually do instead of a copy written once.
 *
 *   npm run sync:modules                      # fetch from GitHub, write to the DB
 *   npm run sync:modules -- --dry-run         # report what would change, touch nothing
 *   npm run sync:modules -- --from-disk /workspace   # read local clones instead of GitHub
 *   npm run sync:modules -- --only pwa_delivery,signage
 *
 * What it writes, per manifest:
 *   tfb_modules              upsert on `key`
 *   tfb_module_features      upsert on (module_id, key); missing ones are deactivated,
 *                            never deleted, so their translations survive a bad manifest
 *   tfb_module_screenshots   rewritten from the manifest, files copied into STORAGE_PATH
 *   tfb_translations         name / description / overview / metric_label per locale,
 *                            for the module, its functions and its screenshots
 *
 * Nothing here deletes a module: a repo that goes quiet keeps its landing page until
 * someone deactivates it in the back office.
 *
 * Private repos need GITHUB_TOKEN (contents: read).
 */
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { PrismaClient } from '@prisma/client';
import { MANIFEST_PATH, ManifestError, parseManifest, type ManifestScreenshot, type ModuleManifest } from '../src/lib/sync/manifest';
import { LIST_SEPARATOR } from '../src/lib/i18n/translations';

const prisma = new PrismaClient();

const STORAGE_ROOT = process.env.STORAGE_PATH || './storage';
const STORAGE_PREFIX = '/storage';
const IMAGE_EXT = new Set(['.png', '.jpg', '.jpeg', '.webp', '.svg']);

type RepoEntry = { repo: string; ref?: string };
type Registry = { defaultRef?: string; repos: RepoEntry[] };

type Options = {
  dryRun: boolean;
  fromDisk: string | null;
  only: string[] | null;
  retireUnlisted: boolean;
};

function parseArgs(argv: string[]): Options {
  const opts: Options = { dryRun: false, fromDisk: null, only: null, retireUnlisted: false };
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (arg === '--dry-run') opts.dryRun = true;
    else if (arg === '--retire-unlisted') opts.retireUnlisted = true;
    else if (arg === '--from-disk') opts.fromDisk = argv[++i] ?? '.';
    else if (arg.startsWith('--from-disk=')) opts.fromDisk = arg.slice('--from-disk='.length);
    else if (arg === '--only') opts.only = (argv[++i] ?? '').split(',').filter(Boolean);
    else if (arg.startsWith('--only=')) opts.only = arg.slice('--only='.length).split(',').filter(Boolean);
    else if (arg !== '') throw new Error(`Option inconnue : ${arg}`);
  }
  return opts;
}

/* ----------------------------------------------------------------- sources ---- */

const TOKEN = process.env.GITHUB_TOKEN ?? '';

/**
 * Two ways to read a file from GitHub, and the difference matters.
 *
 * The Contents API honours a token, so it is the only way into a private repo.
 * raw.githubusercontent does NOT: hand it an `Authorization` header it doesn't
 * accept and it answers **404**, not 401 — a missing token looks exactly like a
 * missing file, which is the worst possible failure mode for this script.
 *
 * So: with a token, ask the API. If the token turns out to be wrong or scoped
 * elsewhere (401/403), fall back to unauthenticated raw, which is enough for a
 * public repo. Never send the token to raw.
 */
let apiDisabled = false;

async function fetchFromApi(repo: string, ref: string, file: string): Promise<Buffer | null | 'unauthorised'> {
  const url = `https://api.github.com/repos/${repo}/contents/${file}?ref=${encodeURIComponent(ref)}`;
  const res = await fetch(url, {
    headers: {
      Accept: 'application/vnd.github.raw',
      'User-Agent': 'tfb-sync-modules',
      Authorization: `Bearer ${TOKEN}`,
    },
  });
  if (res.status === 401 || res.status === 403) return 'unauthorised';
  if (res.status === 404) return null;
  if (!res.ok) throw new Error(`${repo}/${file} : HTTP ${res.status} (API)`);
  return Buffer.from(await res.arrayBuffer());
}

async function fetchFromRaw(repo: string, ref: string, file: string): Promise<Buffer | null> {
  const res = await fetch(`https://raw.githubusercontent.com/${repo}/${ref}/${file}`);
  if (res.status === 404) return null;
  if (!res.ok) throw new Error(`${repo}/${file} : HTTP ${res.status} (raw)`);
  return Buffer.from(await res.arrayBuffer());
}

/** Reads one file from a repo — local clone when --from-disk, GitHub otherwise. */
async function readRepoFile(repo: string, ref: string, file: string, opts: Options): Promise<Buffer | null> {
  if (opts.fromDisk) {
    // Clones are named after the repo, lowercased — that is how `git clone` lands them.
    const dir = path.join(opts.fromDisk, repo.split('/')[1]!.toLowerCase());
    try {
      return await readFile(path.join(dir, file));
    } catch {
      return null;
    }
  }

  if (TOKEN && !apiDisabled) {
    const viaApi = await fetchFromApi(repo, ref, file);
    if (viaApi !== 'unauthorised') return viaApi;
    apiDisabled = true;
    console.warn('  ! GITHUB_TOKEN refusé par l’API GitHub — lecture publique via raw pour la suite');
  }
  return fetchFromRaw(repo, ref, file);
}

/* ------------------------------------------------------------------ writes ---- */

/** Copies a manifest screenshot into STORAGE_PATH and returns the stored relative path. */
async function storeScreenshot(
  repo: string,
  ref: string,
  shot: ManifestScreenshot,
  moduleKey: string,
  stem: string,
  index: number,
  opts: Options,
): Promise<string | null> {
  const ext = path.extname(shot.file).toLowerCase();
  if (!IMAGE_EXT.has(ext)) {
    console.warn(`  ! ${moduleKey} : ${shot.file} — extension non gérée, ignorée`);
    return null;
  }
  const bytes = await readRepoFile(repo, ref, shot.file, opts);
  if (!bytes) {
    console.warn(`  ! ${moduleKey} : ${shot.file} introuvable dans ${repo}`);
    return null;
  }
  const filename = `${moduleKey}-${stem}-${index + 1}${ext}`;
  const relative = `${STORAGE_PREFIX}/screenshots/${filename}`;
  if (!opts.dryRun) {
    const dir = path.resolve(STORAGE_ROOT, 'screenshots');
    await mkdir(dir, { recursive: true });
    await writeFile(path.join(dir, filename), bytes);
  }
  return relative;
}

type TranslationRow = { entityType: string; entityId: number; field: string; languageCode: string; value: string };

/** Queues one row per locale present in the manifest, skipping locales the DB doesn't know. */
function pushLocalised(
  rows: TranslationRow[],
  known: Set<string>,
  entityType: string,
  entityId: number,
  field: string,
  value: Record<string, string> | undefined,
  unknown: Set<string>,
): void {
  if (!value) return;
  for (const [languageCode, text] of Object.entries(value)) {
    if (!known.has(languageCode)) {
      unknown.add(languageCode);
      continue;
    }
    // An empty string would defeat the fallback — an absent row is what it keys off.
    if (text.trim() === '') continue;
    rows.push({ entityType, entityId, field, languageCode, value: text });
  }
}

async function syncModule(manifest: ModuleManifest, repo: string, ref: string, opts: Options, known: Set<string>): Promise<void> {
  const unknownLocales = new Set<string>();
  const rows: TranslationRow[] = [];

  if (opts.dryRun) {
    const shots = (manifest.screenshots?.length ?? 0) + (manifest.features ?? []).reduce((n, f) => n + (f.screenshots?.length ?? 0), 0);
    console.log(`  ${manifest.key} — ${manifest.features?.length ?? 0} fonction(s), ${shots} capture(s), locales ${Object.keys(manifest.name).join('/')}`);
    return;
  }

  const module = await prisma.module.upsert({
    where: { key: manifest.key },
    create: {
      key: manifest.key,
      slug: manifest.slug,
      icon: manifest.icon ?? 'layers',
      moduleGroup: manifest.group,
      repo,
      redirectUrl: `/modules/${manifest.slug}`,
      isNew: manifest.isNew ?? true,
      isActive: true,
      sortOrder: manifest.sortOrder ?? 0,
    },
    update: {
      slug: manifest.slug,
      icon: manifest.icon ?? 'layers',
      moduleGroup: manifest.group,
      repo,
      redirectUrl: `/modules/${manifest.slug}`,
      ...(manifest.isNew === undefined ? {} : { isNew: manifest.isNew }),
      ...(manifest.sortOrder === undefined ? {} : { sortOrder: manifest.sortOrder }),
    },
  });

  pushLocalised(rows, known, 'module', module.id, 'name', manifest.name, unknownLocales);
  pushLocalised(rows, known, 'module', module.id, 'description', manifest.description, unknownLocales);
  pushLocalised(rows, known, 'module', module.id, 'overview', manifest.overview, unknownLocales);
  if (manifest.metric) {
    // metric_value is a figure, not copy — one row in the default locale is enough,
    // and the resolver falls back to it for every other language.
    rows.push({ entityType: 'module', entityId: module.id, field: 'metric_value', languageCode: 'fr', value: manifest.metric.value });
    pushLocalised(rows, known, 'module', module.id, 'metric_label', manifest.metric.label, unknownLocales);
  }
  // The bullet list is the function names — it keeps the older card layout truthful.
  const featureNames = (manifest.features ?? []).map((f) => f.name);
  for (const locale of known) {
    const line = featureNames.map((n) => n[locale] ?? n.fr).filter(Boolean).join(LIST_SEPARATOR);
    if (line) rows.push({ entityType: 'module', entityId: module.id, field: 'bullets', languageCode: locale, value: line });
  }

  // ---- Functions ---------------------------------------------------------
  const featureIds = new Map<string, number>();
  for (const [i, f] of (manifest.features ?? []).entries()) {
    const row = await prisma.moduleFeature.upsert({
      where: { moduleId_key: { moduleId: module.id, key: f.key } },
      create: { moduleId: module.id, key: f.key, icon: f.icon ?? null, sortOrder: i + 1, isActive: true },
      update: { icon: f.icon ?? null, sortOrder: i + 1, isActive: true },
    });
    featureIds.set(f.key, row.id);
    pushLocalised(rows, known, 'feature', row.id, 'name', f.name, unknownLocales);
    pushLocalised(rows, known, 'feature', row.id, 'description', f.description, unknownLocales);
  }

  // A function the manifest dropped is deactivated, not deleted: the landing stops
  // showing it, and its translations are still there if the repo brings it back.
  const retired = await prisma.moduleFeature.updateMany({
    where: { moduleId: module.id, key: { notIn: [...featureIds.keys()] }, isActive: true },
    data: { isActive: false },
  });
  if (retired.count > 0) console.log(`  – ${manifest.key} : ${retired.count} fonction(s) retirée(s) du manifeste, désactivée(s)`);

  // ---- Screenshots -------------------------------------------------------
  // Rewritten wholesale: the manifest is the order and the content.
  const stored: { filePath: string; featureId: number | null; alt?: Record<string, string> }[] = [];
  for (const [i, shot] of (manifest.screenshots ?? []).entries()) {
    const filePath = await storeScreenshot(repo, ref, shot, manifest.key, 'general', i, opts);
    if (filePath) stored.push({ filePath, featureId: null, alt: shot.alt });
  }
  for (const f of manifest.features ?? []) {
    for (const [i, shot] of (f.screenshots ?? []).entries()) {
      const filePath = await storeScreenshot(repo, ref, shot, manifest.key, f.key, i, opts);
      if (filePath) stored.push({ filePath, featureId: featureIds.get(f.key) ?? null, alt: shot.alt });
    }
  }

  const previous = await prisma.moduleScreenshot.findMany({ where: { moduleId: module.id }, select: { id: true } });
  await prisma.translation.deleteMany({ where: { entityType: 'screenshot', entityId: { in: previous.map((p) => p.id) } } });
  await prisma.moduleScreenshot.deleteMany({ where: { moduleId: module.id } });
  for (const [i, s] of stored.entries()) {
    const row = await prisma.moduleScreenshot.create({
      data: { moduleId: module.id, featureId: s.featureId, filePath: s.filePath, sortOrder: i + 1 },
    });
    pushLocalised(rows, known, 'screenshot', row.id, 'alt', s.alt, unknownLocales);
  }

  // ---- Translations ------------------------------------------------------
  for (const r of rows) {
    await prisma.translation.upsert({
      where: {
        entityType_entityId_field_languageCode: {
          entityType: r.entityType, entityId: r.entityId, field: r.field, languageCode: r.languageCode,
        },
      },
      create: r,
      update: { value: r.value },
    });
  }

  if (unknownLocales.size > 0) {
    console.warn(`  ! ${manifest.key} : locale(s) inconnue(s) ignorée(s) — ${[...unknownLocales].join(', ')}`);
  }
  console.log(`  ✓ ${manifest.key} — ${featureIds.size} fonction(s), ${stored.length} capture(s), ${rows.length} traduction(s)`);
}

/* -------------------------------------------------------------------- main ---- */

async function main(): Promise<void> {
  const opts = parseArgs(process.argv.slice(2));
  const registryPath = path.resolve('content/modules.repos.json');
  const registry: Registry = JSON.parse(await readFile(registryPath, 'utf8'));
  const defaultRef = registry.defaultRef ?? 'main';

  let entries = registry.repos;
  if (opts.only) {
    const wanted = opts.only.map((s) => s.toLowerCase());
    entries = entries.filter((e) => wanted.some((w) => e.repo.toLowerCase().includes(w)));
  }

  console.log(
    `sync:modules — ${entries.length} dépôt(s), source ${opts.fromDisk ? `disque (${opts.fromDisk})` : 'GitHub'}${opts.dryRun ? ', dry-run' : ''}`,
  );

  // Locales are the DB's business; a manifest can only fill in the ones that exist.
  const known = new Set<string>(
    opts.dryRun && !process.env.DATABASE_URL
      ? ['fr', 'en', 'nl', 'de', 'pl', 'uk', 'ru', 'ar']
      : (await prisma.language.findMany({ select: { code: true } })).map((l) => l.code),
  );

  const groups = new Set<string>();
  const synced: string[] = [];
  // A repo that has not published a fiche yet is a known state, not a break — it is
  // reported and the run stays green. A fiche that is malformed or unreachable is a
  // break, and fails the run.
  let missing = 0;
  let failures = 0;

  for (const entry of entries) {
    const ref = entry.ref ?? defaultRef;
    try {
      const bytes = await readRepoFile(entry.repo, ref, MANIFEST_PATH, opts);
      if (!bytes) {
        console.warn(`  – ${entry.repo} : pas encore de ${MANIFEST_PATH} sur ${ref} — ignoré`);
        missing += 1;
        continue;
      }
      const manifest = parseManifest(JSON.parse(bytes.toString('utf8')), entry.repo);
      groups.add(manifest.group);
      synced.push(manifest.key);
      await syncModule(manifest, entry.repo, ref, opts, known);
    } catch (err) {
      failures += 1;
      const message = err instanceof ManifestError || err instanceof Error ? err.message : String(err);
      console.error(`  ✗ ${entry.repo} : ${message}`);
    }
  }

  // The seed ships demo modules that no repository backs. `--retire-unlisted` takes
  // them off the landing — deactivated, not deleted, and only when every listed repo
  // answered, so one unreachable manifest can never empty the page.
  if (opts.retireUnlisted && !opts.dryRun && !opts.only) {
    if (failures > 0 || missing > 0) {
      console.warn('  ! --retire-unlisted ignoré : un dépôt au moins n’a pas livré sa fiche');
    } else {
      const retired = await prisma.module.updateMany({
        where: { key: { notIn: synced }, isActive: true },
        data: { isActive: false },
      });
      if (retired.count > 0) console.log(`  – ${retired.count} module(s) sans manifeste, désactivé(s)`);
    }
  }

  // A group with no `group.<lowercased>` ui string renders as its raw value.
  if (!opts.dryRun) {
    const uiRows = await prisma.translation.findMany({
      where: { entityType: 'ui', entityId: 0, field: { startsWith: 'group.' }, languageCode: 'fr' },
      select: { field: true },
    });
    const translated = new Set(uiRows.map((r) => r.field));
    for (const g of groups) {
      if (!translated.has(`group.${g.toLowerCase()}`)) {
        console.warn(`  ! groupe « ${g} » sans traduction ui group.${g.toLowerCase()} — le filtre affichera la valeur brute`);
      }
    }
  }

  console.log(
    `sync:modules — ${synced.length} module(s) traité(s)` +
      (missing > 0 ? `, ${missing} sans fiche` : '') +
      (failures > 0 ? `, ${failures} en échec` : ''),
  );
  if (failures > 0) process.exitCode = 1;
}

main()
  .catch((err) => {
    console.error(err);
    process.exitCode = 1;
  })
  .finally(() => prisma.$disconnect());
