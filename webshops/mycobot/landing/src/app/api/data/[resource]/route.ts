import { NextResponse } from 'next/server';
import { getSession } from '@/lib/auth/session';
import { getBillingSnapshot } from '@/lib/billing/client';
import { resource as catalogueResource } from '@/lib/data/catalogue';
import { parseRequest, runProxyResource, runQueryResource, RequestRefused } from '@/lib/data/query';
import { isSlug } from '@/lib/config/store';
import { draftOverlay, globalOverlay, publishedOverlay } from '@/lib/config/store';
import { resolveAll, resolvedParam } from '@/lib/config/resolve';
import type { ConfigValue } from '@/lib/config/registry';

type Ctx = { params: Promise<{ resource: string }> };

/**
 * GET /api/data/:resource
 *
 * One handler for the whole catalogue. Adding a resource is an entry in
 * catalogue.ts — never a new route file, which is what keeps "same endpoints,
 * coherent structure" true as the number of tenants grows.
 *
 * ON AUTHENTICATION — read before wiring a PWA to this.
 * A tenant is only ever identified server-side. This cut resolves it from a back
 * office session (the console's preview), because this app has no tenant-facing
 * authentication yet: the billing service holds the API keys and only exposes
 * `api_key_hint`, so there is nothing here to verify a PWA's token against.
 *
 * Rather than trust `?tenant=` from an anonymous caller — which is exactly how one
 * customer ends up reading another's rows — an unauthenticated call is refused. Wire
 * `resolveTenant` to the billing service's key check when it exists; no other line of
 * this file changes.
 */
async function resolveTenant(url: URL): Promise<{ slug: string; id: string } | NextResponse> {
  const session = await getSession();
  if (!session) {
    return NextResponse.json(
      {
        error:
          'Authentification tenant requise. Cette route ne déduit jamais le tenant d’un paramètre d’URL — ' +
          'brancher la vérification de clé du service de facturation avant d’y connecter une PWA.',
      },
      { status: 401 },
    );
  }

  const slug = url.searchParams.get('tenant') ?? '';
  if (!isSlug(slug)) return NextResponse.json({ error: 'Paramètre tenant manquant ou invalide.' }, { status: 400 });

  const snapshot = await getBillingSnapshot();
  const tenant = snapshot.tenants.find((t) => t.slug === slug);
  if (!tenant) return NextResponse.json({ error: `Tenant inconnu : ${slug}.` }, { status: 404 });
  return { slug: tenant.slug, id: tenant.id };
}

export async function GET(request: Request, ctx: Ctx) {
  const key = (await ctx.params).resource;
  const entry = catalogueResource(key);
  if (!entry) return NextResponse.json({ error: `Ressource absente du catalogue : ${key}.` }, { status: 404 });

  const url = new URL(request.url);
  const tenant = await resolveTenant(url);
  if (tenant instanceof NextResponse) return tenant;

  try {
    if (entry.kind === 'query') {
      const options = parseRequest(url, entry);
      const rows = await runQueryResource(entry, tenant.id, options);
      return NextResponse.json(
        { resource: key, version: entry.version, count: rows.length, truncated: rows.length === options.limit, rows },
        { headers: { 'Cache-Control': 'private, no-store' } },
      );
    }

    // Proxy parameters are fed by the tenant's own resolved variables — this is what
    // "piloter la bibliothèque d'endpoints par des variables" means in practice.
    const wantsDraft = url.searchParams.get('draft') === '1';
    const [tenantOverlay, global] = await Promise.all([
      wantsDraft ? draftOverlay(tenant.slug) : publishedOverlay(tenant.slug),
      globalOverlay(),
    ]);
    const resolutions = resolveAll(tenantOverlay, global);
    const params: Record<string, ConfigValue> = {};
    for (const spec of Object.values(entry.params ?? {})) params[spec.from] = resolvedParam(resolutions, spec.from);

    const { rows } = await runProxyResource(entry, tenant.slug, params);
    return NextResponse.json(
      { resource: key, version: entry.version, rows },
      { headers: { 'Cache-Control': 'private, no-store' } },
    );
  } catch (error) {
    if (error instanceof RequestRefused) {
      return NextResponse.json({ error: error.message }, { status: error.status });
    }
    console.error(`GET /api/data/${key} failed`, error);
    return NextResponse.json({ error: 'Ressource indisponible.' }, { status: 503 });
  }
}

/**
 * Writes are never generated. A generated read that is wrong is recoverable; a
 * generated write that skips a business rule is not.
 */
export async function POST() {
  return NextResponse.json(
    { error: 'Le catalogue ne génère aucune écriture. POST et PUT restent des endpoints écrits à la main.' },
    { status: 405 },
  );
}

export const PUT = POST;
export const PATCH = POST;
export const DELETE = POST;
