import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { DEFAULT_LOCALE, isLocale } from '@/lib/i18n/config';
import { rateLimit, clientIp } from '@/lib/rate-limit';

/**
 * POST /api/contact — writes a tfb_contact_messages row (§3.1).
 *
 * Anti-spam: a honeypot field (`website`) that real users never fill, plus a
 * per-IP rate limit. Both fail closed with a 2xx-shaped "accepted" so a bot
 * learns nothing from the response.
 */

const LIMIT = 5;
const WINDOW_MS = 10 * 60 * 1000;

const MAX = { name: 120, email: 150, company: 150, message: 4000 };

function str(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

// Deliberately permissive: the point is to catch typos, not to police addresses.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export async function POST(request: Request) {
  const limit = rateLimit(`contact:${clientIp(request)}`, LIMIT, WINDOW_MS);
  if (!limit.ok) {
    return NextResponse.json(
      { error: 'Trop de messages envoyés. Réessayez plus tard.' },
      { status: 429, headers: { 'Retry-After': String(limit.retryAfter) } },
    );
  }

  let body: Record<string, unknown>;
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return NextResponse.json({ error: 'Corps de requête invalide.' }, { status: 400 });
  }

  // Honeypot: silently accept and drop. A bot sees success and stops retrying.
  if (str(body.website)) return NextResponse.json({ ok: true });

  const name = str(body.name).slice(0, MAX.name);
  const email = str(body.email).slice(0, MAX.email);
  const company = str(body.company).slice(0, MAX.company);
  const message = str(body.message).slice(0, MAX.message);
  const requestedLocale = str(body.languageCode);
  const languageCode = isLocale(requestedLocale) ? requestedLocale : DEFAULT_LOCALE;

  const errors: Record<string, string> = {};
  if (!name) errors.name = 'Champ obligatoire';
  if (!email) errors.email = 'Champ obligatoire';
  else if (!EMAIL_RE.test(email)) errors.email = 'Adresse e-mail invalide';
  if (!message) errors.message = 'Champ obligatoire';
  if (Object.keys(errors).length > 0) {
    return NextResponse.json({ error: 'Formulaire incomplet.', fields: errors }, { status: 422 });
  }

  try {
    await prisma.contactMessage.create({
      data: { name, email, company: company || null, message, languageCode, status: 'new' },
    });
  } catch (error) {
    console.error('POST /api/contact failed', error);
    return NextResponse.json({ error: 'Enregistrement impossible.' }, { status: 503 });
  }

  // An email notification would go here (§3.1 marks it optional); the row is the
  // record of truth and the inbox screen reads it immediately.
  return NextResponse.json({ ok: true }, { status: 201 });
}
