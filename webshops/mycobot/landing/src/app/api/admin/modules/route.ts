import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest } from '@/lib/api';

/**
 * GET /api/admin/modules — modules with their screenshot counts (§3.3).
 * This is the list the morning routine fills in.
 */
export const GET = withAdmin(async () => {
  const modules = await prisma.module.findMany({
    orderBy: { sortOrder: 'asc' },
    include: { _count: { select: { screenshots: true } } },
  });
  return NextResponse.json({
    modules: modules.map((m) => ({ ...m, screenshotCount: m._count.screenshots })),
  });
});

/**
 * POST /api/admin/modules — also the endpoint the morning routine calls when it
 * detects a new feature in a repo.
 */
export async function POST(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{
      key?: string; slug?: string; icon?: string; moduleGroup?: string;
      repo?: string; redirectUrl?: string; sortOrder?: number;
    }>(request);
    if (!body?.key) return badRequest('key est obligatoire.');

    const slug = body.slug || body.key;
    const clash = await prisma.module.findFirst({ where: { OR: [{ key: body.key }, { slug }] } });
    if (clash) return badRequest(`« ${body.key} » ou le slug « ${slug} » existe déjà.`);

    const moduleRow = await prisma.module.create({
      data: {
        key: body.key,
        slug,
        icon: body.icon || 'layers',
        moduleGroup: body.moduleGroup || null,
        repo: body.repo || null,
        redirectUrl: body.redirectUrl || `/modules/${slug}`,
        // Draft: invisible on the landing until its translations are written.
        isActive: false,
        isNew: true,
        sortOrder: body.sortOrder ?? 0,
      },
    });
    return NextResponse.json({ module: moduleRow }, { status: 201 });
  })(request, undefined);
}
