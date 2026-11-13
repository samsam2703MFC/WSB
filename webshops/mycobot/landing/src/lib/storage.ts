import { mkdir, writeFile, unlink } from 'node:fs/promises';
import path from 'node:path';
import { randomBytes } from 'node:crypto';

/**
 * Server-side file storage. Binaries live in folders under STORAGE_PATH; the DB
 * stores only the relative path (prompt_technique_tfb.md §0 and §3.4).
 *
 * Stored paths look like "/storage/screenshots/scan-a1b2c3.png". They are served
 * back through GET /api/storage/[...path], which resolves them under STORAGE_PATH.
 */

export const STORAGE_ROOT = process.env.STORAGE_PATH || './storage';
const PUBLIC_PREFIX = '/storage';

/** Categories the upload endpoint accepts — one folder each. */
export const STORAGE_CATEGORIES = ['screenshots', 'brands'] as const;
export type StorageCategory = (typeof STORAGE_CATEGORIES)[number];

const ALLOWED_TYPES: Record<string, string> = {
  'image/png': 'png',
  'image/jpeg': 'jpg',
  'image/webp': 'webp',
  'image/svg+xml': 'svg',
};

export const MAX_UPLOAD_BYTES = 4 * 1024 * 1024; // 4 Mo, the hint FileDrop shows.

export class UploadError extends Error {}

/** Maps a stored relative path to the URL the browser requests. */
export function storageUrl(relativePath: string | null | undefined): string | null {
  if (!relativePath) return null;
  if (/^https?:\/\//.test(relativePath)) return relativePath;
  const clean = relativePath.startsWith('/') ? relativePath : `/${relativePath}`;
  // "/storage/screenshots/x.png" → "/api/storage/screenshots/x.png"
  const withoutPrefix = clean.startsWith(`${PUBLIC_PREFIX}/`) ? clean.slice(PUBLIC_PREFIX.length) : clean;
  return `/api/storage${withoutPrefix}`;
}

/**
 * Resolves a stored path to an absolute file path, refusing anything that escapes
 * STORAGE_ROOT. Returns null when the path is not inside the root.
 */
export function resolveStoragePath(segments: string[]): string | null {
  const root = path.resolve(STORAGE_ROOT);
  const target = path.resolve(root, ...segments);
  const withSep = root.endsWith(path.sep) ? root : root + path.sep;
  if (target !== root && !target.startsWith(withSep)) return null;
  return target;
}

function safeStem(name: string): string {
  const base = path.basename(name).replace(/\.[^.]+$/, '');
  const slug = base
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 48);
  return slug || 'file';
}

/**
 * Writes an uploaded file into <STORAGE_PATH>/<category>/ and returns the relative
 * path to persist in the DB. Validates MIME type and size.
 */
export async function saveUpload(file: File, category: StorageCategory): Promise<{ path: string; size: number }> {
  const ext = ALLOWED_TYPES[file.type];
  if (!ext) throw new UploadError(`Type non autorisé : ${file.type || 'inconnu'}. PNG, JPG, WebP ou SVG.`);
  if (file.size > MAX_UPLOAD_BYTES) throw new UploadError('Fichier trop lourd — 4 Mo maximum.');

  const dir = resolveStoragePath([category]);
  if (!dir) throw new UploadError('Chemin de stockage invalide.');
  await mkdir(dir, { recursive: true });

  const filename = `${safeStem(file.name)}-${randomBytes(4).toString('hex')}.${ext}`;
  const buffer = Buffer.from(await file.arrayBuffer());
  await writeFile(path.join(dir, filename), buffer);

  return { path: `${PUBLIC_PREFIX}/${category}/${filename}`, size: buffer.byteLength };
}

/** Deletes a stored file. Missing files are not an error — the DB row is the source of truth. */
export async function deleteUpload(relativePath: string): Promise<void> {
  const clean = relativePath.startsWith(`${PUBLIC_PREFIX}/`) ? relativePath.slice(PUBLIC_PREFIX.length + 1) : relativePath.replace(/^\/+/, '');
  const target = resolveStoragePath(clean.split('/'));
  if (!target) return;
  try {
    await unlink(target);
  } catch {
    /* already gone */
  }
}

export const CONTENT_TYPES: Record<string, string> = {
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
};
