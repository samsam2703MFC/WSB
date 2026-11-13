import { NextResponse } from 'next/server';
import { getSession, type AdminSession } from '@/lib/auth/session';

/**
 * Shared plumbing for the protected admin API (§3.3). Every handler is wrapped so
 * an unauthenticated call can never reach a Prisma write.
 */

export type AdminHandler<T> = (session: AdminSession, context: T) => Promise<NextResponse>;

export function withAdmin<T>(handler: AdminHandler<T>) {
  return async (_request: Request, context: T): Promise<NextResponse> => {
    const session = await getSession();
    if (!session) return NextResponse.json({ error: 'Authentification requise.' }, { status: 401 });
    try {
      return await handler(session, context);
    } catch (error) {
      console.error('Admin API handler failed', error);
      return NextResponse.json({ error: 'Opération impossible.' }, { status: 500 });
    }
  };
}

/** Parses a JSON body, returning null when it is malformed. */
export async function readJson<T = Record<string, unknown>>(request: Request): Promise<T | null> {
  try {
    return (await request.json()) as T;
  } catch {
    return null;
  }
}

export function badRequest(message: string, extra?: Record<string, unknown>) {
  return NextResponse.json({ error: message, ...extra }, { status: 400 });
}

export function notFound(message = 'Introuvable.') {
  return NextResponse.json({ error: message }, { status: 404 });
}

/** Numeric route param, or null when it is not a positive integer. */
export function intParam(value: string): number | null {
  const n = Number(value);
  return Number.isInteger(n) && n > 0 ? n : null;
}
