/**
 * Catalogue proposals — what the introspection wizard produces.
 *
 * A proposal is inert: it is stored, listed and reviewed, and it is served by no
 * route. It becomes real only when someone pastes the generated entry into
 * catalogue.ts and it goes through review. That gap is deliberate — it is what keeps
 * "generated" from meaning "in production".
 *
 * Stored in tfb_settings under one key, so this costs no migration either.
 */

import { prisma } from '@/lib/prisma';
import { isIdentifier, type FilterOp } from './catalogue';

const KEY = 'catalogue.proposals';

export interface Proposal {
  /** Catalogue key the entry would take, e.g. "stock.movements". */
  key: string;
  source: string;
  sourceKind: 'table' | 'view';
  columns: string[];
  filters: Record<string, FilterOp[]>;
  orderable: string[];
  maxRows: number;
  tenantColumn: string;
  createdAt: string;
  createdBy: string;
}

/** The catalogue key must be dotted lowercase, since it goes into a URL path. */
const KEY_SHAPE = /^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/;

export function validateProposal(p: Partial<Proposal>): string | null {
  if (!p.key || !KEY_SHAPE.test(p.key)) return 'Clé attendue au format « groupe.ressource », en minuscules.';
  if (!p.source || !isIdentifier(p.source)) return 'Source invalide.';
  if (!p.columns?.length) return 'Sélectionnez au moins une colonne à exposer.';
  if (!p.columns.every(isIdentifier)) return 'Nom de colonne invalide.';
  // Without a tenant column a multi-tenant read has no server-side scope, which is
  // the one failure mode that leaks one customer's rows to another.
  if (!p.tenantColumn || !isIdentifier(p.tenantColumn)) return 'La colonne portant le tenant est obligatoire.';
  if (p.columns.includes(p.tenantColumn)) return 'La colonne de tenant ne doit pas être exposée — elle sert à filtrer, pas à sortir.';
  if (!p.maxRows || p.maxRows < 1 || p.maxRows > 1000) return 'Le plafond de lignes doit être compris entre 1 et 1000.';
  for (const column of Object.keys(p.filters ?? {})) {
    if (!p.columns.includes(column)) return `Filtre sur une colonne non exposée : ${column}.`;
  }
  for (const column of p.orderable ?? []) {
    if (!p.columns.includes(column)) return `Tri sur une colonne non exposée : ${column}.`;
  }
  return null;
}

export async function listProposals(): Promise<Proposal[]> {
  const row = await prisma.setting.findUnique({ where: { key: KEY } });
  const value = row?.value as { items?: Proposal[] } | null;
  return Array.isArray(value?.items) ? value.items : [];
}

async function save(items: Proposal[]): Promise<void> {
  await prisma.setting.upsert({
    where: { key: KEY },
    create: { key: KEY, value: { items } as never },
    update: { value: { items } as never },
  });
}

export async function addProposal(proposal: Proposal): Promise<void> {
  const items = await listProposals();
  // Same key twice means the reviewer sees the newer intent, not two of them.
  await save([proposal, ...items.filter((p) => p.key !== proposal.key)]);
}

export async function removeProposal(key: string): Promise<void> {
  const items = await listProposals();
  await save(items.filter((p) => p.key !== key));
}

/** The entry exactly as it would read in catalogue.ts — what a reviewer sees in the diff. */
export function proposalCode(p: Proposal): string {
  const filters = Object.entries(p.filters)
    .map(([column, ops]) => `    ${column}: [${ops.map((o) => `'${o}'`).join(', ')}],`)
    .join('\n');

  return [
    `  '${p.key}': {`,
    `    kind: 'query',`,
    `    version: 1,`,
    `    label: '…',`,
    `    source: '${p.source}',`,
    `    columns: [${p.columns.map((c) => `'${c}'`).join(', ')}],`,
    filters ? `    filters: {\n${filters}\n    },` : `    filters: {},`,
    `    orderable: [${p.orderable.map((c) => `'${c}'`).join(', ')}],`,
    `    maxRows: ${p.maxRows},`,
    `    tenantColumn: '${p.tenantColumn}',`,
    `  },`,
  ].join('\n');
}
