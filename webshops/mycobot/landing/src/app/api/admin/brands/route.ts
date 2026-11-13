import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest } from '@/lib/api';

/** GET /api/admin/brands — every enseigne, active or not (§3.3). */
export const GET = withAdmin(async () => {
  const brands = await prisma.brand.findMany({ orderBy: { sortOrder: 'asc' } });
  return NextResponse.json({ brands });
});

/** POST /api/admin/brands */
export async function POST(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{ name?: string; slug?: string; websiteUrl?: string; sortOrder?: number }>(request);
    if (!body?.name || !body.slug) return badRequest('name et slug sont obligatoires.');

    const existing = await prisma.brand.findUnique({ where: { slug: body.slug } });
    if (existing) return badRequest(`Le slug « ${body.slug} » existe déjà.`);

    const brand = await prisma.brand.create({
      data: {
        name: body.name,
        slug: body.slug,
        websiteUrl: body.websiteUrl || null,
        sortOrder: body.sortOrder ?? 0,
      },
    });
    return NextResponse.json({ brand }, { status: 201 });
  })(request, undefined);
}
