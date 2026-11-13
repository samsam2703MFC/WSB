/**
 * `.tfb/module.json` — the fiche a module repository publishes about itself.
 *
 * The landing owns no product copy of its own: each module repo describes what it
 * is, which functions it ships and which screens prove them, and `npm run
 * sync:modules` pulls those manifests into tfb_modules / tfb_module_features /
 * tfb_module_screenshots / tfb_translations. A repo evolves, its manifest evolves,
 * the landing follows on the next run.
 *
 * Copy is a `{ locale: string }` map. `fr` is required — it is what every other
 * locale falls back to (tfb_languages.is_default). Any of the 8 active locales may
 * be present; unknown codes are reported and skipped rather than written.
 */

export const MANIFEST_PATH = '.tfb/module.json';

/** Translatable string: at least French, optionally the other active locales. */
export type LocalisedString = { fr: string } & Record<string, string>;

export interface ManifestScreenshot {
  /** Path to the image inside the module repo, e.g. "docs/landing/agenda.png". */
  file: string;
  /** Alt text per locale. Optional — the landing falls back to a generic caption. */
  alt?: LocalisedString;
}

export interface ManifestFeature {
  /** Stable identifier inside the module. Sync matches on it, so renaming a key
   *  creates a new function and retires the old one. */
  key: string;
  /** Icon file stem in the landing's public/icons. */
  icon?: string;
  name: LocalisedString;
  /** What the function does, in prose — this is what the landing renders. */
  description: LocalisedString;
  screenshots?: ManifestScreenshot[];
}

export interface ManifestMetric {
  /** The figure itself: "12 min", "+18%", "0". Never translated. */
  value: string;
  /** What the figure counts, per locale. */
  label: LocalisedString;
}

export interface ModuleManifest {
  /** Matches tfb_modules.key. Immutable — it is the sync's join key. */
  key: string;
  /** URL segment: /[locale]/modules/<slug>. */
  slug: string;
  /** Business group; drives the landing's filter chips. A new value needs a
   *  `group.<lowercased>` ui translation, and sync says so when one is missing. */
  group: string;
  icon?: string;
  isNew?: boolean;
  sortOrder?: number;
  name: LocalisedString;
  /** One line, shown on the module card. */
  description: LocalisedString;
  /** A paragraph — what the module is for, who uses it. */
  overview?: LocalisedString;
  metric?: ManifestMetric;
  /** General frames, shown in the module hero carousel. */
  screenshots?: ManifestScreenshot[];
  features?: ManifestFeature[];
}

export class ManifestError extends Error {}

const KEY_RE = /^[a-z0-9][a-z0-9_-]*$/;
const SLUG_RE = /^[a-z0-9][a-z0-9-]*$/;

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function localised(value: unknown, where: string): LocalisedString {
  if (!isRecord(value)) throw new ManifestError(`${where} doit être un objet { fr: "…" }.`);
  for (const [code, text] of Object.entries(value)) {
    if (typeof text !== 'string') throw new ManifestError(`${where}.${code} doit être une chaîne.`);
  }
  const fr = value.fr;
  if (typeof fr !== 'string' || fr.trim() === '') {
    throw new ManifestError(`${where}.fr est obligatoire — c'est la locale de repli.`);
  }
  return value as LocalisedString;
}

function optionalLocalised(value: unknown, where: string): LocalisedString | undefined {
  return value === undefined || value === null ? undefined : localised(value, where);
}

function screenshots(value: unknown, where: string): ManifestScreenshot[] {
  if (value === undefined || value === null) return [];
  if (!Array.isArray(value)) throw new ManifestError(`${where} doit être un tableau.`);
  return value.map((raw, i) => {
    if (!isRecord(raw)) throw new ManifestError(`${where}[${i}] doit être un objet.`);
    const file = raw.file;
    if (typeof file !== 'string' || file.trim() === '') {
      throw new ManifestError(`${where}[${i}].file est obligatoire.`);
    }
    // The file is fetched from the repo — a path escaping it is a bug or an attack.
    if (file.startsWith('/') || file.includes('..') || /^https?:/i.test(file)) {
      throw new ManifestError(`${where}[${i}].file doit être un chemin relatif dans le dépôt.`);
    }
    return { file, alt: optionalLocalised(raw.alt, `${where}[${i}].alt`) };
  });
}

/** Parses and validates a manifest. Throws ManifestError with a French message. */
export function parseManifest(raw: unknown, source: string): ModuleManifest {
  if (!isRecord(raw)) throw new ManifestError(`${source} : la racine doit être un objet JSON.`);

  const key = raw.key;
  if (typeof key !== 'string' || !KEY_RE.test(key)) {
    throw new ManifestError(`${source} : "key" doit être en minuscules ([a-z0-9_-]).`);
  }
  const slug = typeof raw.slug === 'string' && raw.slug !== '' ? raw.slug : key;
  if (!SLUG_RE.test(slug)) {
    throw new ManifestError(`${source} : "slug" doit être en minuscules ([a-z0-9-]).`);
  }
  const group = raw.group;
  if (typeof group !== 'string' || group.trim() === '') {
    throw new ManifestError(`${source} : "group" est obligatoire (Ventes, Finance, Terrain…).`);
  }

  let metric: ManifestMetric | undefined;
  if (raw.metric !== undefined && raw.metric !== null) {
    if (!isRecord(raw.metric)) throw new ManifestError(`${source} : "metric" doit être un objet.`);
    if (typeof raw.metric.value !== 'string' || raw.metric.value === '') {
      throw new ManifestError(`${source} : "metric.value" est obligatoire.`);
    }
    metric = { value: raw.metric.value, label: localised(raw.metric.label, `${source} : metric.label`) };
  }

  const seen = new Set<string>();
  const features: ManifestFeature[] = Array.isArray(raw.features)
    ? raw.features.map((f, i) => {
        const where = `${source} : features[${i}]`;
        if (!isRecord(f)) throw new ManifestError(`${where} doit être un objet.`);
        if (typeof f.key !== 'string' || !KEY_RE.test(f.key)) {
          throw new ManifestError(`${where}.key doit être en minuscules ([a-z0-9_-]).`);
        }
        if (seen.has(f.key)) throw new ManifestError(`${where}.key « ${f.key} » est en double.`);
        seen.add(f.key);
        return {
          key: f.key,
          icon: typeof f.icon === 'string' ? f.icon : undefined,
          name: localised(f.name, `${where}.name`),
          description: localised(f.description, `${where}.description`),
          screenshots: screenshots(f.screenshots, `${where}.screenshots`),
        };
      })
    : [];
  if (raw.features !== undefined && !Array.isArray(raw.features)) {
    throw new ManifestError(`${source} : "features" doit être un tableau.`);
  }

  return {
    key,
    slug,
    group: group.trim(),
    icon: typeof raw.icon === 'string' ? raw.icon : undefined,
    isNew: raw.isNew === undefined ? undefined : Boolean(raw.isNew),
    sortOrder: typeof raw.sortOrder === 'number' ? raw.sortOrder : undefined,
    name: localised(raw.name, `${source} : name`),
    description: localised(raw.description, `${source} : description`),
    overview: optionalLocalised(raw.overview, `${source} : overview`),
    metric,
    screenshots: screenshots(raw.screenshots, `${source} : screenshots`),
    features,
  };
}
