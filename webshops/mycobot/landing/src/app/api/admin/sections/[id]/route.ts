import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest, notFound, intParam } from '@/lib/api';

type Ctx = { params: Promise<{ id: string }> };

/** PATCH /api/admin/sections/:id — toggle is_active, change type, edit config. */
export async function PATCH(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const body = await readJson<{ isActive?: boolean; type?: string; sortOrder?: number; config?: unknown }>(request);
    if (!body) return badRequest('Corps de requête invalide.');

    const section = await prisma.section.findUnique({ where: { id } });
    if (!section) return notFound('Section introuvable.');

    const updated = await prisma.section.update({
      where: { id },
      data: {
        ...(body.isActive !== undefined ? { isActive: body.isActive } : {}),
        ...(body.type !== undefined ? { type: body.type } : {}),
        ...(body.sortOrder !== undefined ? { sortOrder: body.sortOrder } : {}),
        ...(body.config !== undefined ? { config: body.config as never } : {}),
      },
    });
    return NextResponse.json({ section: updated });
  })(request, ctx);
}

/** DELETE /api/admin/sections/:id */
export async function DELETE(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const section = await prisma.section.findUnique({ where: { id } });
    if (!section) return notFound('Section introuvable.');

    await prisma.$transaction([
      prisma.translation.deleteMany({ where: { entityType: 'section', entityId: id } }),
      prisma.section.delete({ where: { id } }),
    ]);
    return NextResponse.json({ ok: true });
  })(request, ctx);
}
