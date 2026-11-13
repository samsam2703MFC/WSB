import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest, notFound, intParam } from '@/lib/api';
import { deleteUpload } from '@/lib/storage';

type Ctx = { params: Promise<{ id: string }> };

/** PATCH /api/admin/brands/:id — rename, reorder, toggle is_active, set logo_path. */
export async function PATCH(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const body = await readJson<{ name?: string; websiteUrl?: string | null; isActive?: boolean; sortOrder?: number; logoPath?: string | null }>(request);
    if (!body) return badRequest('Corps de requête invalide.');

    const brand = await prisma.brand.findUnique({ where: { id } });
    if (!brand) return notFound('Enseigne introuvable.');

    // Replacing a logo orphans the previous file — remove it from disk.
    if (body.logoPath !== undefined && brand.logoPath && brand.logoPath !== body.logoPath) {
      await deleteUpload(brand.logoPath);
    }

    const updated = await prisma.brand.update({
      where: { id },
      data: {
        ...(body.name !== undefined ? { name: body.name } : {}),
        ...(body.websiteUrl !== undefined ? { websiteUrl: body.websiteUrl } : {}),
        ...(body.isActive !== undefined ? { isActive: body.isActive } : {}),
        ...(body.sortOrder !== undefined ? { sortOrder: body.sortOrder } : {}),
        ...(body.logoPath !== undefined ? { logoPath: body.logoPath } : {}),
      },
    });
    return NextResponse.json({ brand: updated });
  })(request, ctx);
}

/** DELETE /api/admin/brands/:id — also drops its tfb_translations rows. */
export async function DELETE(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const brand = await prisma.brand.findUnique({ where: { id } });
    if (!brand) return notFound('Enseigne introuvable.');

    // tfb_translations has no FK to the entity (it is polymorphic), so the rows
    // must be removed explicitly or they linger against a reused id.
    await prisma.$transaction([
      prisma.translation.deleteMany({ where: { entityType: 'brand', entityId: id } }),
      prisma.brand.delete({ where: { id } }),
    ]);
    if (brand.logoPath) await deleteUpload(brand.logoPath);

    return NextResponse.json({ ok: true });
  })(request, ctx);
}
