import { NextResponse } from 'next/server';
import { withAdmin, badRequest } from '@/lib/api';
import { saveUpload, UploadError, STORAGE_CATEGORIES, type StorageCategory } from '@/lib/storage';

/**
 * POST /api/admin/upload (§3.4)
 *
 * Generic upload: writes to <STORAGE_PATH>/<category>/ and returns the relative
 * path for the caller to persist (brand logos use this; module screenshots have
 * their own endpoint that also creates the DB row).
 *
 * multipart/form-data: `file`, `category`.
 */
export async function POST(request: Request) {
  return withAdmin(async () => {
    let form: FormData;
    try {
      form = await request.formData();
    } catch {
      return badRequest('Requête multipart attendue.');
    }

    const category = String(form.get('category') ?? '');
    if (!(STORAGE_CATEGORIES as readonly string[]).includes(category)) {
      return badRequest(`category doit être : ${STORAGE_CATEGORIES.join(', ')}.`);
    }

    const file = form.get('file');
    if (!(file instanceof File)) return badRequest('Aucun fichier reçu.');

    try {
      const saved = await saveUpload(file, category as StorageCategory);
      return NextResponse.json({ path: saved.path, size: saved.size }, { status: 201 });
    } catch (error) {
      if (error instanceof UploadError) return NextResponse.json({ error: error.message }, { status: 422 });
      throw error;
    }
  })(request, undefined);
}
