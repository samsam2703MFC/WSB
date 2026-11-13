import { getBillingSnapshot } from '@/lib/billing/client';
import { REGISTRY } from '@/lib/config/registry';
import { draftOverlay, globalOverlay, publishedOverlay } from '@/lib/config/store';
import { resolveAll, grantsFor, configErrors } from '@/lib/config/resolve';
import type { ProfileData } from '@/components/admin/ProfileEditor';

/**
 * Everything the configuration editor needs for one client, resolved server-side.
 *
 * Extracted so the client hub and the editor cannot drift apart on what "invalid" or
 * "overridden" means — there is one place that decides.
 */
export async function buildProfileData(slug: string): Promise<ProfileData | null> {
  const [snapshot, draft, global, published, entitlement] = await Promise.all([
    getBillingSnapshot(),
    draftOverlay(slug),
    globalOverlay(),
    publishedOverlay(slug),
    grantsFor(slug),
  ]);

  const tenant = snapshot.tenants.find((t) => t.slug === slug);
  if (!tenant) return null;

  const licences = snapshot.licenses.filter((l) => l.tenant === tenant.name);
  const licenceStatus = licences.find((l) => l.store === null)?.status ?? licences[0]?.status ?? null;

  return {
    tenant: {
      slug: tenant.slug,
      name: tenant.name,
      internalApiUrl: tenant.internal_api_url,
      stores: tenant.stores,
      licenseStatus: licenceStatus,
    },
    variables: [...REGISTRY],
    resolutions: resolveAll(draft, global),
    errors: configErrors(draft, global),
    dirty: draft.updatedAt !== null,
    publishedVersion: published.version,
    publishedAt: published.updatedAt,
    grants: entitlement.grants,
    packages: snapshot.packages
      .filter((p) => p.is_active)
      .map((p) => ({
        code: p.code,
        name: p.name,
        granted: entitlement.grants.includes(p.code),
        status: licences.find((l) => l.package === p.code)?.status ?? (p.is_free ? 'core' : null),
      })),
    fixture: snapshot.fixture,
  };
}
