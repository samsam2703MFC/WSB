import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { NextResponse } from 'next/server';
import { CONTENT_TYPES, resolveStoragePath } from '@/lib/storage';

/**
 * GET /api/storage/<category>/<file> — serves an uploaded file.
 *
 * Media never lives in the database (prompt_technique_tfb.md §0): the row keeps a
 * relative path like "/storage/screenshots/scan-a1b2c3.png" and this route resolves
 * it under STORAGE_PATH. Two rules it must not break:
 *
 * - Nothing escapes the storage root. `resolveStoragePath` returns null for any
 *   path that resolves outside it, and "..%2F" style tricks are already decoded
 *   into the segments by the time we see them.
 * - An uploaded SVG is executable markup. It is served under a sandboxing CSP so a
 *   file someone dropped in the back office cannot run script in our origin.
 */
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  const { path: segments } = await params;
  if (!segments || segments.length === 0) {
    return NextResponse.json({ error: 'Chemin manquant.' }, { status: 400 });
  }

  const target = resolveStoragePath(segments);
  if (!target) {
    return NextResponse.json({ error: 'Chemin invalide.' }, { status: 400 });
  }

  const type = CONTENT_TYPES[path.extname(target).toLowerCase()];
  if (!type) {
    // Only the types saveUpload accepts are served back.
    return NextResponse.json({ error: 'Type de fichier non servi.' }, { status: 415 });
  }

  let body: Buffer;
  try {
    const info = await stat(target);
    if (!info.isFile()) return NextResponse.json({ error: 'Fichier introuvable.' }, { status: 404 });
    body = await readFile(target);
  } catch {
    // A missing file is the normal state for a seeded path whose capture has not
    // been uploaded yet — the carousel shows its placeholder on a 404.
    return NextResponse.json({ error: 'Fichier introuvable.' }, { status: 404 });
  }

  const headers: Record<string, string> = {
    'Content-Type': type,
    'Content-Length': String(body.byteLength),
    'Cache-Control': 'public, max-age=3600, must-revalidate',
    'X-Content-Type-Options': 'nosniff',
  };
  if (type === 'image/svg+xml') {
    headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
  }

  return new NextResponse(new Uint8Array(body), { headers });
}
