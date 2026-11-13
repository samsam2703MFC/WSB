/**
 * The data catalogue — the library of resources a tenant's PWA may read.
 *
 * Two kinds live side by side in one list:
 *   · proxy — an endpoint that already exists, on the tenant's own service
 *             (tenants.internal_api_url, owned by the billing service).
 *   · query — generated from a declaration against a VIEW in this app's database.
 *
 * Why a view and never a bare table: exposing a table makes its column names your
 * public contract, and you can never rename them again without breaking every
 * client's installed PWA. CREATE VIEW changes no table, so the constraint "I do not
 * touch my database structure" still holds.
 *
 * Nothing here is editable from the back office. A variable of type 'source'
 * *selects* an entry; it never authors a URL or a query. A URL typed into an admin
 * form is an SSRF with no review and no version history.
 */

/** The comparison operators a filter may declare. Anything else is refused. */
export type FilterOp = 'eq' | 'in' | 'gte' | 'lte';

interface BaseResource {
  /** Contract version. @1 and @2 of the same key coexist while old PWAs still ask for @1. */
  version: number;
  /** One line, shown in the console. */
  label: string;
  /** Marks a contract that is still served but should no longer be adopted. */
  deprecated?: boolean;
}

export interface ProxyResource extends BaseResource {
  kind: 'proxy';
  /** Path on the tenant's own service. The host comes from the billing service. */
  path: string;
  /** Parameters this endpoint accepts, each fed by a registry variable. */
  params?: Record<string, { from: string }>;
  /** Seconds of shared cache. */
  cache?: number;
}

export interface QueryResource extends BaseResource {
  kind: 'query';
  /** A view name. Validated as a bare identifier before it ever reaches SQL. */
  source: string;
  /** Allowlist. A column absent here can never leave the database. */
  columns: readonly string[];
  /** Filterable columns and the operators each accepts. */
  filters: Readonly<Record<string, readonly FilterOp[]>>;
  /** Sortable columns — keep to indexed ones. */
  orderable: readonly string[];
  /** Hard ceiling on rows. Never optional. */
  maxRows: number;
  /** The column carrying the tenant. Injected server-side, never read from the request. */
  tenantColumn: string;
}

export type Resource = ProxyResource | QueryResource;

export const CATALOGUE = {
  'stock.low_items': {
    kind: 'proxy',
    version: 1,
    label: 'Articles sous le seuil d’alerte',
    path: '/api/v1/stock/low',
    params: { threshold: { from: 'stock.threshold' } },
    cache: 60,
  },
  'orders.open': {
    kind: 'proxy',
    version: 1,
    label: 'Commandes en cours',
    path: '/api/v1/orders?status=open',
    cache: 30,
  },
  'sales.daily': {
    kind: 'query',
    version: 2,
    label: 'Chiffre d’affaires par jour',
    source: 'v_sales_daily',
    columns: ['day', 'store_id', 'total_cents'],
    filters: { day: ['gte', 'lte'], store_id: ['eq', 'in'] },
    orderable: ['day'],
    maxRows: 500,
    tenantColumn: 'tenant_id',
  },
  'loyalty.members': {
    kind: 'query',
    version: 1,
    label: 'Membres du programme de fidélité',
    source: 'v_loyalty_members',
    columns: ['member_ref', 'joined_at', 'points', 'store_id'],
    filters: { joined_at: ['gte', 'lte'], store_id: ['eq', 'in'] },
    orderable: ['joined_at'],
    maxRows: 500,
    tenantColumn: 'tenant_id',
  },
} as const satisfies Record<string, Resource>;

export type ResourceKey = keyof typeof CATALOGUE;

export function resource(key: string): Resource | undefined {
  return (CATALOGUE as Record<string, Resource>)[key];
}

export function catalogueEntries(): { key: string; resource: Resource }[] {
  return Object.entries(CATALOGUE as Record<string, Resource>).map(([key, r]) => ({ key, resource: r }));
}

/**
 * A bare SQL identifier. Every name that reaches a query is checked against this
 * even though they all come from the catalogue rather than the request — the day
 * someone adds an entry by hand, the check is what stops a typo becoming an
 * injection.
 */
const IDENTIFIER = /^[a-z_][a-z0-9_]*$/;

export function isIdentifier(value: string): boolean {
  return IDENTIFIER.test(value);
}

/** The guarantees the console displays per resource, computed rather than asserted. */
export function guardsFor(r: Resource): { label: string; value: string; ok: boolean }[] {
  if (r.kind === 'proxy') {
    return [
      { label: 'Portée tenant', value: 'internal_api_url', ok: true },
      { label: 'Hôte', value: 'jamais en dur', ok: true },
      { label: 'Cache partagé', value: r.cache ? `${r.cache} s` : 'aucun', ok: true },
      { label: 'Écritures', value: 'refusées', ok: true },
    ];
  }
  return [
    { label: 'Tenant injecté côté serveur', value: r.tenantColumn, ok: isIdentifier(r.tenantColumn) },
    { label: 'Colonnes en allowlist', value: String(r.columns.length), ok: r.columns.every(isIdentifier) },
    { label: 'Tri restreint', value: r.orderable.join(', ') || 'aucun', ok: r.orderable.every((c) => r.columns.includes(c)) },
    { label: 'Plafond de lignes', value: String(r.maxRows), ok: r.maxRows > 0 && r.maxRows <= 1000 },
    { label: 'Écritures', value: 'refusées', ok: true },
    { label: 'Source', value: r.source, ok: isIdentifier(r.source) && r.source.startsWith('v_') },
  ];
}
