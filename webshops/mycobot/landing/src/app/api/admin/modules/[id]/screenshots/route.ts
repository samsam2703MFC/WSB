import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest, notFound, intParam } from '@/lib/api';
import { saveUpload, UploadError } from '@/lib/storage';

type Ctx = { params: Promise<{ id: string }> };

/** GET /api/admin/modules/:id/screenshots */
export async function GET(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');
    const screenshots = await prisma.moduleScreenshot.findMany({ where: { moduleId: id }, orderBy: { sortOrder: 'asc' } });
    return NextResponse.json({ screenshots });
  })(request, ctx);
}

/**
 * POST /api/admin/modules/:id/screenshots — multipart upload (§3.4).
 * Writes each file under <STORAGE_PATH>/screenshots/ and stores the relative path.
 */
export async function POST(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const moduleRow = await prisma.module.findUnique({ where: { id } });
    if (!moduleRow) return notFound('Module introuvable.');

    let form: FormData;
    try {
      form = await request.formData();
    } catch {
      return badRequest('Requête multipart attendue.');
    }

    const files = form.getAll('files').filter((f): f is File => f instanceof File);
    if (files.length === 0) return badRequest('Aucun fichier reçu.');

    const last = await prisma.moduleScreenshot.findFirst({ where: { moduleId: id }, orderBy: { sortOrder: 'desc' } });
    let sortOrder = (last?.sortOrder ?? 0) + 1;

    const created = [];
    for (const file of files) {
      try {
        const saved = await saveUpload(file, 'screenshots');
        created.push(
          await prisma.moduleScreenshot.create({ data: { moduleId: id, filePath: saved.path, sortOrder } }),
        );
        sortOrder += 1;
      } catch (error) {
        // One bad file must not discard the ones already stored; report it and
        // keep the rest.
        if (error instanceof UploadError) {
          return NextResponse.json({ error: error.message, created }, { status: 422 });
        }
        throw error;
      }
    }

    return NextResponse.json({ screenshots: created }, { status: 201 });
  })(request, ctx);
}

/**
 * PUT /api/admin/modules/:id/screenshots — reorder the carousel.
 * Body: { order: [{ id, sortOrder }] }
 */
export async function PUT(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const body = await readJson<{ order?: { id: number; sortOrder: number }[] }>(request);
    if (!Array.isArray(body?.order)) return badRequest('order doit être un tableau.');

    // Scoped to this module so a caller cannot reorder someone else's carousel.
    await prisma.$transaction(
      body.order.map((row) =>
        prisma.moduleScreenshot.updateMany({ where: { id: row.id, moduleId: id }, data: { sortOrder: row.sortOrder } }),
      ),
    );
    return NextResponse.json({ ok: true });
  })(request, ctx);
}
