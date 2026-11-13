import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest, notFound, intParam } from '@/lib/api';

type Ctx = { params: Promise<{ id: string }> };

const STATUSES = ['new', 'read', 'archived'] as const;

/** PATCH /api/admin/contact-messages/:id — mark read / archive. */
export async function PATCH(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const body = await readJson<{ status?: string }>(request);
    const status = body?.status;
    if (!status || !(STATUSES as readonly string[]).includes(status)) {
      return badRequest(`status doit être : ${STATUSES.join(', ')}.`);
    }

    const existing = await prisma.contactMessage.findUnique({ where: { id } });
    if (!existing) return notFound('Message introuvable.');

    const message = await prisma.contactMessage.update({ where: { id }, data: { status } });
    return NextResponse.json({ message });
  })(request, ctx);
}
