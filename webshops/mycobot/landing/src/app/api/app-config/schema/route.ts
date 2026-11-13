import { NextResponse } from 'next/server';
import { REGISTRY } from '@/lib/config/registry';
import { catalogueEntries } from '@/lib/data/catalogue';

/**
 * GET /api/app-config/schema
 *
 * The variable registry and the catalogue keys, as data. This is what makes the back
 * office generic: it builds its form from this response instead of hard-coding one
 * screen per variable, so adding a variable is one entry in registry.ts and no UI
 * work at all.
 *
 * Public and cacheable: it describes the shape of the configuration, never a
 * tenant's values.
 */
export async function GET() {
  return NextResponse.json(
    {
      variables: REGISTRY,
      resources: catalogueEntries().map(({ key, resource }) => ({
        key,
        kind: resource.kind,
        version: resource.version,
        label: resource.label,
        deprecated: resource.deprecated ?? false,
      })),
    },
    { headers: { 'Cache-Control': 'public, s-maxage=300, stale-while-revalidate=3600' } },
  );
}
