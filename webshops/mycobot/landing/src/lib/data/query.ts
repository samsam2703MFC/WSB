/**
 * Execution for the two catalogue kinds.
 *
 * Everything that reaches SQL as an identifier comes from the catalogue — never from
 * the request. The request may only name a filter that the catalogue already
 * declares, and its value is always a bound parameter. That is the whole safety
 * argument, and it is why the catalogue is code rather than rows in a table.
 */

import { prisma } from '@/lib/prisma';
import { getBillingSnapshot } from '@/lib/billing/client';
import { isIdentifier, type FilterOp, type ProxyResource, type QueryResource } from './catalogue';
import type { ConfigValue } from '@/lib/config/registry';

export interface ParsedFilter {
  column: string;
  op: FilterOp;
  value: string;
}

export interface RequestOptions {
  filters: ParsedFilter[];
  limit: number;
  order?: { column: string; dir: 'asc' | 'desc' };
}

export class RequestRefused extends Error {
  constructor(
    message: string,
    readonly status: number = 400,
  ) {
    super(message);
  }
}

/**
 * Reads filter[col][op]=value, limit and order out of a URL against one resource.
 * Anything the resource does not declare is refused rather than ignored — silently
 * dropping a filter would return more rows than the caller asked for.
 */
export function parseRequest(url: URL, r: QueryResource): RequestOptions {
  const filters: ParsedFilter[] = [];

  for (const [rawKey, value] of url.searchParams.entries()) {
    const match = /^filter\[([^\]]+)\]\[([^\]]+)\]$/.exec(rawKey);
    if (!match) continue;
    const [, column, op] = match as unknown as [string, string, string];

    const allowed = r.filters[column];
    if (!allowed) throw new RequestRefused(`Filtre non déclaré pour cette ressource : ${column}.`);
    if (!allowed.includes(op as FilterOp)) {
      throw new RequestRefused(`Opérateur « ${op} » non autorisé sur ${column} — déclarés : ${allowed.join(', ')}.`);
    }
    filters.push({ column, op: op as FilterOp, value });
  }

  const requested = Number(url.searchParams.get('limit') ?? r.maxRows);
  const limit = Number.isInteger(requested) && requested > 0 ? Math.min(requested, r.maxRows) : r.maxRows;

  let order: RequestOptions['order'];
  const orderBy = url.searchParams.get('order');
  if (orderBy) {
    const [column, dir] = orderBy.split(':');
    if (!column || !r.orderable.includes(column)) {
      throw new RequestRefused(`Tri non autorisé — colonnes triables : ${r.orderable.join(', ') || 'aucune'}.`);
    }
    order = { column, dir: dir === 'asc' ? 'asc' : 'desc' };
  }

  return { filters, limit, order };
}

function quote(identifier: string): string {
  // Belt and braces: these all come from the catalogue, but a hand-added entry with a
  // typo must fail here rather than reach the database.
  if (!isIdentifier(identifier)) throw new RequestRefused(`Identifiant SQL invalide : ${identifier}.`, 500);
  return `\`${identifier}\``;
}

/**
 * Runs a declared query, scoped to one tenant.
 *
 * `tenantId` is passed in by the caller after it has resolved the tenant server-side.
 * It is never read from the query string: a `?tenant=` taken at face value is how
 * one customer reads another customer's rows.
 */
export async function runQueryResource(
  r: QueryResource,
  tenantId: string,
  options: RequestOptions,
): Promise<Record<string, unknown>[]> {
  const columns = r.columns.map(quote).join(', ');
  const where: string[] = [`${quote(r.tenantColumn)} = ?`];
  const values: unknown[] = [tenantId];

  for (const filter of options.filters) {
    const column = quote(filter.column);
    switch (filter.op) {
      case 'eq':
        where.push(`${column} = ?`);
        values.push(filter.value);
        break;
      case 'gte':
        where.push(`${column} >= ?`);
        values.push(filter.value);
        break;
      case 'lte':
        where.push(`${column} <= ?`);
        values.push(filter.value);
        break;
      case 'in': {
        const items = filter.value.split(',').filter(Boolean).slice(0, 100);
        if (items.length === 0) throw new RequestRefused(`Filtre « in » vide sur ${filter.column}.`);
        where.push(`${column} IN (${items.map(() => '?').join(', ')})`);
        values.push(...items);
        break;
      }
    }
  }

  const orderBy = options.order ? ` ORDER BY ${quote(options.order.column)} ${options.order.dir === 'asc' ? 'ASC' : 'DESC'}` : '';
  const sql = `SELECT ${columns} FROM ${quote(r.source)} WHERE ${where.join(' AND ')}${orderBy} LIMIT ?`;
  values.push(options.limit);

  const rows = await prisma.$queryRawUnsafe<Record<string, unknown>[]>(sql, ...values);
  return rows.map(normaliseRow);
}

/**
 * MySQL's driver hands back BIGINT as a JS bigint and DATE as a Date, neither of
 * which JSON.stringify can be trusted with — a bigint makes it throw outright, which
 * turned every resource with an id column into a 503. Normalising here rather than in
 * the route keeps the catalogue's output shape the same for every caller.
 *
 * A bigint beyond Number.MAX_SAFE_INTEGER becomes a string rather than a silently
 * rounded number: a wrong id is worse than an inconvenient one.
 */
function normaliseRow(row: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(row)) {
    if (typeof value === 'bigint') {
      out[key] = value >= BigInt(Number.MIN_SAFE_INTEGER) && value <= BigInt(Number.MAX_SAFE_INTEGER)
        ? Number(value)
        : value.toString();
    } else if (value instanceof Date) {
      out[key] = value.toISOString();
    } else if (Buffer.isBuffer(value)) {
      out[key] = value.toString('base64');
    } else {
      out[key] = value;
    }
  }
  return out;
}

/**
 * Calls a tenant's own service. The host is never in the catalogue and never in this
 * repo — it comes from tenants.internal_api_url, which the billing service owns.
 */
export async function runProxyResource(
  r: ProxyResource,
  slug: string,
  params: Record<string, ConfigValue>,
): Promise<{ rows: unknown; upstream: number }> {
  const snapshot = await getBillingSnapshot();
  const tenant = snapshot.tenants.find((t) => t.slug === slug);
  if (!tenant) throw new RequestRefused(`Tenant inconnu : ${slug}.`, 404);
  if (snapshot.fixture) {
    throw new RequestRefused(
      'BILLING_SERVICE_URL absent — l’URL du service du tenant vient des fixtures, aucun appel n’est fait.',
      503,
    );
  }

  const base = tenant.internal_api_url.replace(/\/$/, '');
  const target = new URL(`${base}${r.path}`);
  for (const [name, spec] of Object.entries(r.params ?? {})) {
    const value = params[spec.from];
    if (value != null) target.searchParams.set(name, String(value));
  }

  const response = await fetch(target, {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  });
  if (!response.ok) throw new RequestRefused(`Service du tenant → HTTP ${response.status}`, 502);
  return { rows: await response.json(), upstream: response.status };
}
