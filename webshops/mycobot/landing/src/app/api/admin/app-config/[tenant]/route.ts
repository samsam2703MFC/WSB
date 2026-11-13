import { NextResponse } from 'next/server';
import { withAdmin, readJson, badRequest } from '@/lib/api';
import { isSlug, draftOverlay, globalOverlay, publishedOverlay, patchDraft } from '@/lib/config/store';
import { resolveAll, grantsFor, configErrors } from '@/lib/config/resolve';
import { REGISTRY, type ConfigValue } from '@/lib/config/registry';

type Ctx = { params: Promise<{ tenant: string }> };

/**
 * GET /api/admin/app-config/:tenant — the draft, resolved layer by layer.
 *
 * Returns the whole chain per key rather than the winning value alone, because the
 * console has to show *why* a value is what it is: "Surchargé" versus "Hérité" is
 * the difference between a deliberate choice and an accident.
 */
export async function GET(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const slug = (await c.params).tenant;
    if (!isSlug(slug)) return badRequest('Slug de tenant invalide.');

    const [draft, global, published, entitlement] = await Promise.all([
      draftOverlay(slug),
      globalOverlay(),
      publishedOverlay(slug),
      grantsFor(slug),
    ]);

    const resolutions = resolveAll(draft, global);
    // Re-validated on read so a registry that tightened since the last write shows
    // the offending rows in red instead of failing only at publish time.
    const errors = configErrors(draft, global);

    return NextResponse.json({
      tenant: slug,
      variables: REGISTRY,
      resolutions,
      errors,
      overrides: Object.keys(draft.values),
      dirty: draft.updatedAt !== null,
      draftUpdatedAt: draft.updatedAt,
      published: { version: published.version, updatedAt: published.updatedAt },
      grants: entitlement.grants,
      tenantRow: entitlement.tenant,
      fixture: entitlement.fixture,
    });
  })(request, ctx);
}

/**
 * PUT /api/admin/app-config/:tenant — patch the draft.
 * Body: { values: { "stock.threshold": 25, "theme.logo": null } }
 *
 * `null` removes the override so the value inherits again — it never stores a blank.
 * Nothing here touches what the PWA is served; that needs an explicit publish.
 */
export async function PUT(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const slug = (await c.params).tenant;
    if (!isSlug(slug)) return badRequest('Slug de tenant invalide.');

    const body = await readJson<{ values?: Record<string, ConfigValue> }>(request);
    if (!body?.values || typeof body.values !== 'object') return badRequest('values est obligatoire.');

    const result = await patchDraft(slug, body.values);
    if (!result.ok) {
      return NextResponse.json({ error: 'Valeurs refusées.', errors: result.errors }, { status: 422 });
    }
    return NextResponse.json({ ok: true, draft: result.draft });
  })(request, ctx);
}
