/**
 * Resolution: registry default → global override → tenant override.
 *
 * This is the same shape the landing already uses for language (getLanding falls
 * back to FR when a tfb_translations row is missing); `tenant` is simply one more
 * dimension on the same builder. An absent entry is inheritance, never a blank.
 *
 * Entitlements are NOT resolved here — they are derived. What a tenant may see comes
 * from licences and packages in the billing service; the overlay only says how it
 * looks. Letting the back office tick entitlements would give two sources of truth
 * about what a customer paid for, and they diverge at the first Stripe webhook.
 */

import { getBillingSnapshot } from '@/lib/billing/client';
import type { Tenant } from '@/lib/billing/types';
import { REGISTRY, registryDefaults, validate, validateTheme, variable, type ConfigValue, type ConfigValues } from './registry';
import { globalOverlay, publishedOverlay, draftOverlay, type Overlay } from './store';

/** Licence statuses that entitle a tenant to a package. */
const ENTITLING = new Set(['active', 'trialing']);

export interface ResolvedLayer {
  layer: 'tenant' | 'global' | 'registry';
  value: ConfigValue;
}

export interface Resolution {
  key: string;
  value: ConfigValue;
  /** Which layer won — what the console renders as "Surchargé" or "Hérité". */
  from: ResolvedLayer['layer'];
  /** The whole chain, top layer first, for the console's resolution panel. */
  chain: ResolvedLayer[];
}

export interface AppConfig {
  tenant: string;
  version: number;
  etag: string;
  updatedAt: string | null;
  /** Nested by the key's first segment: { theme: {...}, nav: {...} }. */
  config: Record<string, Record<string, ConfigValue>>;
  /** Package codes the tenant is entitled to, derived from licences. */
  grants: string[];
  /** True when the billing service was unreachable and grants come from fixtures. */
  fixture: boolean;
}

/**
 * Every error the console should show for one tenant: per-value shape errors, plus
 * the pair checks that only make sense once the whole theme is resolved.
 *
 * One function so the screen, the admin API and the publish gate cannot disagree
 * about what counts as invalid.
 */
export function configErrors(tenant: Overlay, global: Overlay): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const [key, value] of Object.entries(tenant.values)) {
    const message = variable(key) ? validate(key, value) : `Variable absente du registre : ${key}.`;
    if (message) errors[key] = message;
  }
  // Pair checks run on the resolved set — an inherited surface still decides whether
  // an overridden text colour is legible on it.
  const resolved: ConfigValues = { ...global.values, ...tenant.values };
  for (const [key, message] of Object.entries(validateTheme(resolved))) {
    errors[key] ??= message;
  }
  return errors;
}

/** One key resolved across the three layers. */
export function resolveKey(key: string, tenant: Overlay, global: Overlay): Resolution {
  const defaults = registryDefaults();
  const chain: ResolvedLayer[] = [
    { layer: 'tenant', value: key in tenant.values ? tenant.values[key]! : null },
    { layer: 'global', value: key in global.values ? global.values[key]! : null },
    { layer: 'registry', value: key in defaults ? defaults[key]! : null },
  ];

  if (key in tenant.values) return { key, value: tenant.values[key]!, from: 'tenant', chain };
  if (key in global.values) return { key, value: global.values[key]!, from: 'global', chain };
  return { key, value: defaults[key] ?? null, from: 'registry', chain };
}

export function resolveAll(tenant: Overlay, global: Overlay): Resolution[] {
  return REGISTRY.map((v) => resolveKey(v.key, tenant, global));
}

/**
 * Package codes this tenant is entitled to. Free packages are always granted; the
 * rest need a licence in an entitling status. Licence rows carry the tenant's
 * display name, which is how the billing service's read model joins them.
 */
export async function grantsFor(slug: string): Promise<{ grants: string[]; tenant: Tenant | null; fixture: boolean }> {
  const snapshot = await getBillingSnapshot();
  const tenant = snapshot.tenants.find((t) => t.slug === slug) ?? null;
  if (!tenant) return { grants: [], tenant: null, fixture: snapshot.fixture };

  const granted = new Set<string>();
  for (const pkg of snapshot.packages) if (pkg.is_free && pkg.is_active) granted.add(pkg.code);
  for (const licence of snapshot.licenses) {
    if (licence.tenant !== tenant.name) continue;
    if (ENTITLING.has(licence.status)) granted.add(licence.package);
  }
  return { grants: [...granted].sort(), tenant, fixture: snapshot.fixture };
}

/**
 * The payload a tenant's PWA reads at boot. `draft: true` serves what the console is
 * editing instead of what is published — used by the back office's preview only.
 *
 * nav.modules is filtered against the grants: a module the tenant no longer pays for
 * disappears from the navigation without anybody editing the overlay.
 */
export async function getAppConfig(slug: string, options: { draft?: boolean } = {}): Promise<AppConfig> {
  const [tenantOverlay, global, entitlement] = await Promise.all([
    options.draft ? draftOverlay(slug) : publishedOverlay(slug),
    globalOverlay(),
    grantsFor(slug),
  ]);

  const config: Record<string, Record<string, ConfigValue>> = {};
  for (const resolution of resolveAll(tenantOverlay, global)) {
    const [group, ...rest] = resolution.key.split('.');
    const leaf = rest.join('.');
    if (!group || !leaf) continue;

    let value = resolution.value;
    if (resolution.key === 'nav.modules' && Array.isArray(value)) {
      value = value.filter((moduleKey) => entitlement.grants.includes(moduleKey));
    }
    (config[group] ??= {})[leaf] = value;
  }

  const version = tenantOverlay.version;
  return {
    tenant: slug,
    version,
    // Weak: the body is regenerated per request, but its content only changes on
    // publish, which is exactly what the service worker needs to compare.
    etag: `W/"${slug}-${options.draft ? 'draft' : version}"`,
    updatedAt: tenantOverlay.updatedAt,
    config,
    grants: entitlement.grants,
    fixture: entitlement.fixture,
  };
}

/** The PWA manifest fields that come from the overlay rather than from code. */
export async function getManifestFields(slug: string): Promise<{ name: string; themeColor: string; icon: string | null }> {
  const [tenantOverlay, global] = await Promise.all([publishedOverlay(slug), globalOverlay()]);
  const name = resolveKey('pwa.name', tenantOverlay, global).value;
  const color = resolveKey('theme.primary', tenantOverlay, global).value;
  const icon = resolveKey('theme.logo', tenantOverlay, global).value;
  return {
    name: typeof name === 'string' ? name : 'The Franchise Buddy',
    themeColor: typeof color === 'string' ? color : '#7b4488',
    icon: typeof icon === 'string' ? icon : null,
  };
}

/** Reads a resolved value with its declared type, for feeding catalogue params. */
export function resolvedParam(resolutions: Resolution[], key: string): ConfigValue {
  const found = resolutions.find((r) => r.key === key);
  if (!found) return variable(key)?.default ?? null;
  return found.value;
}

export type { ConfigValues };
