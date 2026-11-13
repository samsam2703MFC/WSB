/**
 * Introspection — reads information_schema to *propose* catalogue entries.
 *
 * Introspection is excellent at proposing and disastrous at serving. Nothing here
 * ever exposes a route: it produces a declaration that a human reviews and commits,
 * and the runtime keeps serving only what is in catalogue.ts. That is what stops the
 * contents of a database from becoming a public API by accident.
 *
 * Read-only, admin-gated, and scoped to the connection's own schema — every query
 * pins TABLE_SCHEMA = DATABASE(), so this can never enumerate another database on
 * the same server.
 */

import { prisma } from '@/lib/prisma';
import { isIdentifier, type FilterOp } from './catalogue';

export interface SourceSummary {
  name: string;
  kind: 'table' | 'view';
  /** MySQL's estimate — good enough to warn about sorting a large source. */
  rows: number;
  columns: number;
}

export interface ColumnSummary {
  name: string;
  dataType: string;
  columnType: string;
  nullable: boolean;
  /**
   * Indexed on at least one base table carrying a column of this name. For a view
   * MySQL keeps no statistics, so this is a name-based hint, not a guarantee — the
   * console labels it as such.
   */
  indexedHint: boolean;
  /** Looks like a tenant discriminator, so it can be preselected. */
  tenantCandidate: boolean;
  /** Looks commercially or personally sensitive — unchecked by default. */
  sensitive: string | null;
  /** Operators worth offering for this type. */
  suggestedFilters: FilterOp[];
}

/** Column names that should never be exposed without someone deciding to. */
const SENSITIVE: { pattern: RegExp; reason: string }[] = [
  { pattern: /(cost|margin|marge|purchase_price|buy_price)/i, reason: 'marge' },
  { pattern: /(salary|wage|payroll|created_by|updated_by|employee|staff_id)/i, reason: 'donnée RH' },
  { pattern: /(email|phone|tel|address|adresse|iban|birth|nin|ssn)/i, reason: 'donnée personnelle' },
  { pattern: /(password|hash|secret|token|api_key)/i, reason: 'secret' },
];

const TENANT_LIKE = /^(tenant_id|tenant|tenant_slug|organisation_id|organization_id|account_id)$/i;

const DATE_TYPES = new Set(['date', 'datetime', 'timestamp']);
const EXACT_TYPES = new Set(['char', 'varchar', 'bigint', 'int', 'smallint', 'mediumint', 'tinyint']);

function suggestFilters(dataType: string, indexed: boolean): FilterOp[] {
  if (DATE_TYPES.has(dataType)) return ['gte', 'lte'];
  if (indexed && EXACT_TYPES.has(dataType)) return ['eq', 'in'];
  return [];
}

function sensitivityOf(column: string): string | null {
  for (const { pattern, reason } of SENSITIVE) if (pattern.test(column)) return reason;
  return null;
}

/** Tables and views in the connection's own schema. */
export async function listSources(): Promise<SourceSummary[]> {
  const rows = await prisma.$queryRaw<
    { TABLE_NAME: string; TABLE_TYPE: string; TABLE_ROWS: bigint | number | null; COLS: bigint | number }[]
  >`
    SELECT t.TABLE_NAME, t.TABLE_TYPE, t.TABLE_ROWS,
           (SELECT COUNT(*) FROM information_schema.COLUMNS c
             WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME) AS COLS
      FROM information_schema.TABLES t
     WHERE t.TABLE_SCHEMA = DATABASE()
     ORDER BY t.TABLE_TYPE DESC, t.TABLE_NAME ASC
  `;

  return rows
    .filter((r) => isIdentifier(r.TABLE_NAME))
    .map((r) => ({
      name: r.TABLE_NAME,
      kind: r.TABLE_TYPE === 'VIEW' ? ('view' as const) : ('table' as const),
      rows: Number(r.TABLE_ROWS ?? 0),
      columns: Number(r.COLS),
    }));
}

/** Every column name that carries an index somewhere in this schema. */
async function indexedNames(): Promise<Set<string>> {
  const rows = await prisma.$queryRaw<{ COLUMN_NAME: string }[]>`
    SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
  `;
  return new Set(rows.map((r) => r.COLUMN_NAME));
}

/**
 * One source's columns, annotated with everything the wizard needs to make a safe
 * default: what is indexed, what looks like the tenant, and what should stay off.
 */
export async function describeSource(name: string): Promise<ColumnSummary[] | null> {
  // The name reaches a bound parameter, never string interpolation — but it is
  // checked anyway so a malformed value fails here rather than at the database.
  if (!isIdentifier(name)) return null;

  const [columns, indexed] = await Promise.all([
    prisma.$queryRaw<
      { COLUMN_NAME: string; DATA_TYPE: string; COLUMN_TYPE: string; IS_NULLABLE: string }[]
    >`
      SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
        FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ${name}
       ORDER BY ORDINAL_POSITION
    `,
    indexedNames(),
  ]);

  if (columns.length === 0) return null;

  return columns.map((c) => {
    const indexedHint = indexed.has(c.COLUMN_NAME);
    return {
      name: c.COLUMN_NAME,
      dataType: c.DATA_TYPE,
      columnType: c.COLUMN_TYPE,
      nullable: c.IS_NULLABLE === 'YES',
      indexedHint,
      tenantCandidate: TENANT_LIKE.test(c.COLUMN_NAME),
      sensitive: sensitivityOf(c.COLUMN_NAME),
      suggestedFilters: suggestFilters(c.DATA_TYPE, indexedHint),
    };
  });
}

/**
 * The CREATE VIEW that turns a bare table into something safe to expose. Returned as
 * text for someone to run — this app never issues DDL. A back office that can alter
 * the shape of a database is a back office that can do it by accident.
 */
export function createViewStatement(table: string, viewName: string, columns: string[]): string | null {
  if (!isIdentifier(table) || !isIdentifier(viewName) || !columns.every(isIdentifier)) return null;
  return `CREATE OR REPLACE VIEW \`${viewName}\` AS\n  SELECT ${columns.map((c) => `\`${c}\``).join(', ')}\n    FROM \`${table}\`;`;
}

/** The default view name for a table, following the catalogue's v_ convention. */
export function viewNameFor(table: string): string {
  return `v_${table.replace(/^tfb_/, '')}`;
}
