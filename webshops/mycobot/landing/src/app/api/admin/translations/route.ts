import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest } from '@/lib/api';
import { isLocale } from '@/lib/i18n/config';

/**
 * The translation editor's endpoint (§3.3) — every entity, all 8 languages.
 *
 * GET  /api/admin/translations?entityType=module&entityId=4
 *      → { fields: [{ field, values: { fr: '…', en: '…', … } }] }
 * PUT  /api/admin/translations
 *      → { entityType, entityId, values: { '<field>': { '<lang>': '<value>' } } }
 */

const ENTITY_TYPES = ['ui', 'module', 'plan', 'brand', 'section', 'screenshot', 'feature'] as const;

export async function GET(request: Request) {
  return withAdmin(async () => {
    const params = new URL(request.url).searchParams;
    const entityType = params.get('entityType') ?? '';
    const entityIdRaw = params.get('entityId') ?? '';

    if (!(ENTITY_TYPES as readonly string[]).includes(entityType)) {
      return badRequest(`entityType doit être : ${ENTITY_TYPES.join(', ')}.`);
    }
    // 'ui' is the single pseudo-entity at id 0, so 0 is valid here.
    const entityId = Number(entityIdRaw);
    if (!Number.isInteger(entityId) || entityId < 0) return badRequest('entityId invalide.');

    const [rows, languages] = await Promise.all([
      prisma.translation.findMany({ where: { entityType, entityId }, orderBy: { field: 'asc' } }),
      prisma.language.findMany({ where: { isActive: true }, orderBy: { sortOrder: 'asc' } }),
    ]);

    const byField = new Map<string, Record<string, string>>();
    for (const r of rows) {
      if (!byField.has(r.field)) byField.set(r.field, {});
      byField.get(r.field)![r.languageCode] = r.value;
    }

    // Report every active language per field, empty string where nothing exists —
    // the editor renders those cells as "Vide — fallback FR".
    const fields = [...byField.entries()].map(([field, values]) => ({
      field,
      values: Object.fromEntries(languages.map((l) => [l.code, values[l.code] ?? ''])),
    }));

    return NextResponse.json({
      entityType,
      entityId,
      languages: languages.map((l) => ({ code: l.code, name: l.name, isRtl: l.isRtl, isDefault: l.isDefault })),
      fields,
    });
  })(request, undefined);
}

export async function PUT(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{
      entityType?: string;
      entityId?: number;
      values?: Record<string, Record<string, string>>;
    }>(request);

    const entityType = body?.entityType ?? '';
    if (!(ENTITY_TYPES as readonly string[]).includes(entityType)) {
      return badRequest(`entityType doit être : ${ENTITY_TYPES.join(', ')}.`);
    }
    const entityId = body?.entityId;
    if (!Number.isInteger(entityId) || (entityId as number) < 0) return badRequest('entityId invalide.');
    if (!body?.values || typeof body.values !== 'object') return badRequest('values est obligatoire.');

    const writes = [];
    const deletes = [];
    for (const [field, byLang] of Object.entries(body.values)) {
      if (!field || typeof byLang !== 'object') continue;
      for (const [languageCode, raw] of Object.entries(byLang)) {
        if (!isLocale(languageCode)) return badRequest(`Locale inconnue : ${languageCode}.`);
        const value = typeof raw === 'string' ? raw : '';
        const where = {
          entityType_entityId_field_languageCode: { entityType, entityId: entityId as number, field, languageCode },
        };
        if (value === '') {
          // Clearing a cell removes the row rather than storing '' — an absent row
          // is what makes the fallback resolver pick the default locale.
          deletes.push(prisma.translation.deleteMany({ where: { entityType, entityId: entityId as number, field, languageCode } }));
        } else {
          writes.push(
            prisma.translation.upsert({
              where,
              create: { entityType, entityId: entityId as number, field, languageCode, value },
              update: { value },
            }),
          );
        }
      }
    }

    await prisma.$transaction([...deletes, ...writes]);
    return NextResponse.json({ ok: true, written: writes.length, cleared: deletes.length });
  })(request, undefined);
}
