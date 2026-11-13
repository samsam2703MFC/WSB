import { Badge, Card, Icon } from '@/design-system';
import { catalogueEntries, guardsFor } from '@/lib/data/catalogue';
import { consumersOfResource } from '@/lib/config/store';
import { listProposals, proposalCode } from '@/lib/data/proposals';
import { CatalogueImport, CatalogueProposals } from '@/components/admin/CatalogueImport';

/**
 * Catalogue de données — the library of resources a tenant's PWA may read.
 *
 * Read-only on purpose. The catalogue is code: it is reviewed in a pull request and
 * versioned with the app. A console that could add a resource at runtime would put
 * an unreviewed URL, or an unbounded query, one click away from production.
 */
export default async function CataloguePage() {
  const entries = catalogueEntries();
  const [consumers, proposals] = await Promise.all([
    Promise.all(entries.map((e) => consumersOfResource(e.key))),
    listProposals(),
  ]);

  const served = entries.filter((e) => !e.resource.deprecated).length;
  const deprecated = entries.length - served;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1240 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
        <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>
          {entries.length} ressources servies · {proposals.length} proposition(s) en attente de revue
        </span>
        <span style={{ marginInlineStart: 'auto' }}>
          <CatalogueImport />
        </span>
      </div>

      <div role="status" style={infoStyle}>
        <Icon name="info" size={17} style={{ marginTop: 1, flex: 'none' }} />
        <span style={{ font: 'var(--type-body-sm)' }}>
          <strong>Le runtime ne sert que les entrées déclarées ici.</strong> Une variable de type{' '}
          <span className="fb-num">source</span> <em>choisit</em> une entrée — elle n’écrit jamais une URL ni une requête.
          Ajouter une ressource est une entrée dans <span className="fb-num">src/lib/data/catalogue.ts</span>, relue en revue.
        </span>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 'var(--space-3)' }}>
        <Tile label="Ressources servies" value={served} unit={`dont ${entries.filter((e) => e.resource.kind === 'proxy').length} proxy`} />
        <Tile label="Contrats dépréciés" value={deprecated} unit="encore servis" />
        <Tile label="Profils consommateurs" value={new Set(consumers.flat()).size} unit="tenants" />
      </div>

      <CatalogueProposals proposals={proposals.map((p) => ({ ...p, code: proposalCode(p) }))} />

      {entries.map(({ key, resource }, i) => {
        const guards = guardsFor(resource);
        const failing = guards.filter((g) => !g.ok).length;
        return (
          <Card
            key={`${key}@${resource.version}`}
            padding="md"
            title={
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                <span className="fb-num">{key}</span>
                <Badge tone={resource.kind === 'proxy' ? 'info' : 'success'} size="sm">
                  <span className="fb-num">{resource.kind}</span>
                </Badge>
                <Badge tone="neutral" size="sm">
                  <span className="fb-num">@{resource.version}</span>
                </Badge>
                {resource.deprecated ? <Badge tone="warning" dot size="sm">dépréciée</Badge> : null}
              </span>
            }
            subtitle={resource.label}
            actions={
              <Badge tone={failing ? 'danger' : 'success'} size="sm">
                Garde-fous {guards.length - failing} / {guards.length}
              </Badge>
            }
          >
            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 300px', gap: 'var(--space-5)' }}>
              <div>
                <div style={labelStyle}>Déclaration</div>
                <pre style={preStyle}>{declaration(key, resource)}</pre>
              </div>

              <div>
                <div style={labelStyle}>Garde-fous</div>
                {guards.map((g) => (
                  <div key={g.label} style={guardStyle}>
                    <Icon
                      name={g.ok ? 'circle-check' : 'circle-alert'}
                      size={14}
                      color={g.ok ? 'var(--green-500)' : 'var(--status-danger)'}
                    />
                    <span style={{ font: 'var(--type-caption)' }}>{g.label}</span>
                    <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-data)', color: 'var(--text-secondary)' }}>
                      {g.value}
                    </span>
                  </div>
                ))}

                <div style={{ ...labelStyle, marginTop: 'var(--space-4)' }}>Consommée par</div>
                {consumers[i]!.length === 0 ? (
                  <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>aucun profil</span>
                ) : (
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    {consumers[i]!.map((slug) => (
                      <span key={slug} className="fb-num" style={chipStyle}>{slug}</span>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </Card>
        );
      })}
    </div>
  );
}

/** The entry as it is written in catalogue.ts — what the reviewer would see in a diff. */
function declaration(key: string, r: ReturnType<typeof catalogueEntries>[number]['resource']): string {
  if (r.kind === 'proxy') {
    const params = Object.entries(r.params ?? {}).map(([name, spec]) => `    ${name}: { from: '${spec.from}' },`);
    return [
      `'${key}': {`,
      `  kind: 'proxy',`,
      `  version: ${r.version},`,
      `  path: '${r.path}',   // hôte = tenants.internal_api_url`,
      params.length ? `  params: {\n${params.join('\n')}\n  },` : `  params: {},`,
      `  cache: ${r.cache ?? 0},`,
      `}`,
    ].join('\n');
  }
  const filters = Object.entries(r.filters).map(([col, ops]) => `    ${col}: [${ops.map((o) => `'${o}'`).join(', ')}],`);
  return [
    `'${key}': {`,
    `  kind: 'query',`,
    `  version: ${r.version},`,
    `  source: '${r.source}',   // une vue, jamais une table nue`,
    `  columns: [${r.columns.map((c) => `'${c}'`).join(', ')}],`,
    `  filters: {\n${filters.join('\n')}\n  },`,
    `  orderable: [${r.orderable.map((c) => `'${c}'`).join(', ')}],`,
    `  maxRows: ${r.maxRows},`,
    `  tenantColumn: '${r.tenantColumn}',`,
    `}`,
  ].join('\n');
}

function Tile({ label, value, unit }: { label: string; value: number; unit: string }) {
  return (
    <Card padding="md">
      <div style={{ font: 'var(--type-caption)', letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--text-tertiary)' }}>
        {label}
      </div>
      <div style={{ marginTop: 4 }}>
        <span className="fb-num" style={{ font: 'var(--type-heading-3)' }}>{value}</span>
        <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginInlineStart: 6 }}>{unit}</span>
      </div>
    </Card>
  );
}

const infoStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
  padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-card)',
  background: 'var(--surface-info-subtle)', border: '1px solid var(--blue-500)', color: 'var(--blue-700)',
};

const labelStyle: React.CSSProperties = {
  font: 'var(--type-caption)', letterSpacing: 'var(--tracking-caps)',
  textTransform: 'uppercase', color: 'var(--text-tertiary)', marginBottom: 'var(--space-2)',
};

const preStyle: React.CSSProperties = {
  font: 'var(--type-data)', lineHeight: 1.65, color: 'var(--slate-700)',
  background: 'var(--slate-25)', border: '1px solid var(--border-subtle)',
  borderRadius: 'var(--radius-md)', padding: 'var(--space-3)', overflowX: 'auto', margin: 0,
};

const guardStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-2)',
  padding: '6px 0', borderBottom: '1px dashed var(--border-default)',
};

const chipStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', padding: '2px 8px',
  borderRadius: 'var(--radius-chip)', background: 'var(--slate-50)',
  border: '1px solid var(--border-default)', color: 'var(--text-secondary)',
  font: 'var(--type-caption)',
};
