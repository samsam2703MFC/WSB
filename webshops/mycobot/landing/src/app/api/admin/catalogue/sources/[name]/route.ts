import { NextResponse } from 'next/server';
import { withAdmin, badRequest, notFound } from '@/lib/api';
import { describeSource, createViewStatement, viewNameFor } from '@/lib/data/introspect';
import { isIdentifier } from '@/lib/data/catalogue';

type Ctx = { params: Promise<{ name: string }> };

/**
 * GET /api/admin/catalogue/sources/:name — one source's columns, annotated.
 *
 * Also returns the CREATE VIEW that would wrap a bare table, as text. This app never
 * issues DDL: a back office that can reshape a database can do it by accident.
 */
export async function GET(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const name = (await c.params).name;
    if (!isIdentifier(name)) return badRequest('Nom de source invalide.');

    try {
      const columns = await describeSource(name);
      if (!columns) return notFound('Source introuvable dans ce schéma.');

      const viewName = viewNameFor(name);
      const safeColumns = columns.filter((col) => !col.sensitive).map((col) => col.name);

      return NextResponse.json({
        name,
        columns,
        suggestedViewName: viewName,
        // Pre-filled with the non-sensitive columns: the wizard's opt-in default.
        createViewSql: createViewStatement(name, viewName, safeColumns),
      });
    } catch (error) {
      console.error(`Introspection of ${name} failed`, error);
      return NextResponse.json({ error: 'Introspection impossible.' }, { status: 503 });
    }
  })(request, ctx);
}
