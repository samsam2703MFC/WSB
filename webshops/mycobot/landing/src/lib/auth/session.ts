import { cookies } from 'next/headers';
import { SignJWT, jwtVerify } from 'jose';

/**
 * Back-office sessions: a signed JWT in an httpOnly cookie. Roles come straight
 * from tfb_admin_users.role (superadmin | admin).
 */

export const SESSION_COOKIE = 'tfb_admin_session';
const MAX_AGE_SECONDS = 60 * 60 * 8; // one working day

export type AdminRole = 'superadmin' | 'admin';

export interface AdminSession {
  id: number;
  email: string;
  role: AdminRole;
}

function secret(): Uint8Array {
  const raw = process.env.ADMIN_SESSION_SECRET;
  if (!raw || raw.length < 32) {
    throw new Error('ADMIN_SESSION_SECRET is missing or shorter than 32 characters — see .env.example.');
  }
  return new TextEncoder().encode(raw);
}

export async function createSession(session: AdminSession): Promise<void> {
  const token = await new SignJWT({ email: session.email, role: session.role })
    .setProtectedHeader({ alg: 'HS256' })
    .setSubject(String(session.id))
    .setIssuedAt()
    .setExpirationTime(`${MAX_AGE_SECONDS}s`)
    .sign(secret());

  (await cookies()).set(SESSION_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    maxAge: MAX_AGE_SECONDS,
  });
}

export async function destroySession(): Promise<void> {
  (await cookies()).delete(SESSION_COOKIE);
}

/** Returns the current session, or null when the cookie is absent or invalid. */
export async function getSession(): Promise<AdminSession | null> {
  const token = (await cookies()).get(SESSION_COOKIE)?.value;
  if (!token) return null;
  try {
    const { payload } = await jwtVerify(token, secret());
    const id = Number(payload.sub);
    const email = typeof payload.email === 'string' ? payload.email : null;
    const role = payload.role === 'superadmin' ? 'superadmin' : 'admin';
    if (!Number.isInteger(id) || !email) return null;
    return { id, email, role };
  } catch {
    return null;
  }
}

/** Throws a 401-shaped error for API routes; pages should redirect instead. */
export class Unauthorized extends Error {
  constructor() {
    super('Authentification requise.');
  }
}

export async function requireSession(): Promise<AdminSession> {
  const session = await getSession();
  if (!session) throw new Unauthorized();
  return session;
}
