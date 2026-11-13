import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { verifyPassword } from '@/lib/auth/password';
import { createSession, destroySession, type AdminRole } from '@/lib/auth/session';
import { rateLimit, clientIp } from '@/lib/rate-limit';

/** POST /api/admin/auth/login — authenticates against tfb_admin_users (§3.3). */
export async function POST(request: Request) {
  const limit = rateLimit(`login:${clientIp(request)}`, 10, 15 * 60 * 1000);
  if (!limit.ok) {
    return NextResponse.json(
      { error: 'Trop de tentatives. Réessayez dans quelques minutes.' },
      { status: 429, headers: { 'Retry-After': String(limit.retryAfter) } },
    );
  }

  let body: { email?: unknown; password?: unknown };
  try {
    body = (await request.json()) as typeof body;
  } catch {
    return NextResponse.json({ error: 'Corps de requête invalide.' }, { status: 400 });
  }

  const email = typeof body.email === 'string' ? body.email.trim().toLowerCase() : '';
  const password = typeof body.password === 'string' ? body.password : '';
  if (!email || !password) {
    return NextResponse.json({ error: 'E-mail et mot de passe requis.' }, { status: 422 });
  }

  const user = await prisma.adminUser.findUnique({ where: { email } });

  // Same message and comparable work for "no such user" and "wrong password", so
  // the response cannot be used to enumerate accounts.
  const ok = user?.isActive ? await verifyPassword(password, user.passwordHash) : false;
  if (!user || !ok) {
    return NextResponse.json({ error: 'E-mail ou mot de passe incorrect.' }, { status: 401 });
  }

  await createSession({ id: user.id, email: user.email, role: user.role as AdminRole });
  return NextResponse.json({ ok: true, email: user.email, role: user.role });
}

/** DELETE /api/admin/auth/login — sign out. */
export async function DELETE() {
  await destroySession();
  return NextResponse.json({ ok: true });
}
