import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest } from '@/lib/api';

/** GET /api/admin/sections — every landing section in sort_order (§3.3). */
export const GET = withAdmin(async () => {
  const sections = await prisma.section.findMany({ orderBy: { sortOrder: 'asc' } });
  return NextResponse.json({ sections });
});

/** POST /api/admin/sections */
export async function POST(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{ key?: string; type?: string; sortOrder?: number; config?: unknown }>(request);
    if (!body?.key || !body.type) return badRequest('key et type sont obligatoires.');

    const existing = await prisma.section.findUnique({ where: { key: body.key } });
    if (existing) return badRequest(`La section « ${body.key} » existe déjà.`);

    const section = await prisma.section.create({
      data: {
        key: body.key,
        type: body.type,
        sortOrder: body.sortOrder ?? 0,
        // A new section starts inactive so it cannot appear on the landing before
        // its translations exist.
        isActive: false,
        config: (body.config ?? undefined) as never,
      },
    });
    return NextResponse.json({ section }, { status: 201 });
  })(request, undefined);
}

/**
 * PUT /api/admin/sections — reorder in one call.
 * Body: { order: [{ id, sortOrder }] }
 */
export async function PUT(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{ order?: { id: number; sortOrder: number }[] }>(request);
    if (!Array.isArray(body?.order)) return badRequest('order doit être un tableau.');

    // One transaction: a half-applied reorder would render the landing in a
    // sequence nobody chose.
    await prisma.$transaction(
      body.order.map((row) => prisma.section.update({ where: { id: row.id }, data: { sortOrder: row.sortOrder } })),
    );
    return NextResponse.json({ ok: true });
  })(request, undefined);
}
