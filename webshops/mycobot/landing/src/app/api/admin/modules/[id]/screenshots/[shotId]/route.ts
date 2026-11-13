import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, badRequest, notFound, intParam } from '@/lib/api';
import { deleteUpload } from '@/lib/storage';

type Ctx = { params: Promise<{ id: string; shotId: string }> };

/** DELETE /api/admin/modules/:id/screenshots/:shotId — row and file. */
export async function DELETE(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const { id: rawId, shotId: rawShotId } = await c.params;
    const moduleId = intParam(rawId);
    const shotId = intParam(rawShotId);
    if (!moduleId || !shotId) return badRequest('Identifiant invalide.');

    // Match on both ids so a screenshot cannot be deleted through another module.
    const shot = await prisma.moduleScreenshot.findFirst({ where: { id: shotId, moduleId } });
    if (!shot) return notFound('Screenshot introuvable.');

    await prisma.$transaction([
      prisma.translation.deleteMany({ where: { entityType: 'screenshot', entityId: shotId } }),
      prisma.moduleScreenshot.delete({ where: { id: shotId } }),
    ]);
    await deleteUpload(shot.filePath);

    return NextResponse.json({ ok: true });
  })(request, ctx);
}
