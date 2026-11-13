import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin } from '@/lib/api';

const STATUSES = ['new', 'read', 'archived'];

/** GET /api/admin/contact-messages?status=new (§3.3). */
export async function GET(request: Request) {
  return withAdmin(async () => {
    const status = new URL(request.url).searchParams.get('status');
    const messages = await prisma.contactMessage.findMany({
      where: status && STATUSES.includes(status) ? { status } : undefined,
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    const unread = await prisma.contactMessage.count({ where: { status: 'new' } });
    return NextResponse.json({ messages, unread });
  })(request, undefined);
}
