import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest, notFound, intParam } from '@/lib/api';
import { deleteUpload } from '@/lib/storage';

type Ctx = { params: Promise<{ id: string }> };

/** GET /api/admin/modules/:id — the module plus its ordered screenshots. */
export async function GET(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const moduleRow = await prisma.module.findUnique({
      where: { id },
      include: { screenshots: { orderBy: { sortOrder: 'asc' } } },
    });
    if (!moduleRow) return notFound('Module introuvable.');
    return NextResponse.json({ module: moduleRow });
  })(request, ctx);
}

/**
 * PATCH /api/admin/modules/:id
 * `key` is immutable after publication — it is the identifier the morning routine
 * and every translation row key off.
 */
export async function PATCH(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const body = await readJson<{
      slug?: string; icon?: string; moduleGroup?: string | null; repo?: string | null;
      redirectUrl?: string | null; isActive?: boolean; isNew?: boolean; sortOrder?: number;
    }>(request);
    if (!body) return badRequest('Corps de requête invalide.');

    const moduleRow = await prisma.module.findUnique({ where: { id } });
    if (!moduleRow) return notFound('Module introuvable.');

    if (body.slug && body.slug !== moduleRow.slug) {
      const clash = await prisma.module.findUnique({ where: { slug: body.slug } });
      if (clash) return badRequest(`Le slug « ${body.slug} » est déjà pris.`);
    }

    const updated = await prisma.module.update({
      where: { id },
      data: {
        ...(body.slug !== undefined ? { slug: body.slug } : {}),
        ...(body.icon !== undefined ? { icon: body.icon } : {}),
        ...(body.moduleGroup !== undefined ? { moduleGroup: body.moduleGroup } : {}),
        ...(body.repo !== undefined ? { repo: body.repo } : {}),
        ...(body.redirectUrl !== undefined ? { redirectUrl: body.redirectUrl } : {}),
        ...(body.isActive !== undefined ? { isActive: body.isActive } : {}),
        ...(body.isNew !== undefined ? { isNew: body.isNew } : {}),
        ...(body.sortOrder !== undefined ? { sortOrder: body.sortOrder } : {}),
      },
    });
    return NextResponse.json({ module: updated });
  })(request, ctx);
}

/** DELETE /api/admin/modules/:id — cascades screenshots, clears translations and files. */
export async function DELETE(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const moduleRow = await prisma.module.findUnique({ where: { id }, include: { screenshots: true } });
    if (!moduleRow) return notFound('Module introuvable.');

    const shotIds = moduleRow.screenshots.map((s) => s.id);
    await prisma.$transaction([
      prisma.translation.deleteMany({ where: { entityType: 'module', entityId: id } }),
      prisma.translation.deleteMany({ where: { entityType: 'screenshot', entityId: { in: shotIds } } }),
      // tfb_module_screenshots cascades on the FK; the files do not.
      prisma.module.delete({ where: { id } }),
    ]);
    await Promise.all(moduleRow.screenshots.map((s) => deleteUpload(s.filePath)));

    return NextResponse.json({ ok: true });
  })(request, ctx);
}
