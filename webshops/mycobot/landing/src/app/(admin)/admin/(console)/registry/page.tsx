import { Badge, Card, Icon } from '@/design-system';
import { registryGroups, type Variable } from '@/lib/config/registry';

/**
 * Registre des variables — read-only, and that is the design, not a gap.
 *
 * The registry is the one hard-coded thing in this layer: the console builds its
 * forms from it, so it has to be reviewed and versioned like code. Everything a
 * client can change lives in the profile screens; what *can be changed at all* is
 * decided here, in a pull request.
 */
export default function RegistryPage() {
  const groups = registryGroups();
  const total = groups.reduce((n, g) => n + g.variables.length, 0);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1000 }}>
      <div role="status" style={infoStyle}>
        <Icon name="info" size={17} style={{ marginTop: 1, flex: 'none' }} />
        <span style={{ font: 'var(--type-body-sm)' }}>
          <strong>{total} variables déclarées</strong> dans <span className="fb-num">src/lib/config/registry.ts</span>.
          En ajouter une, c’est une entrée — le back office et <span className="fb-num">GET /api/app-config/schema</span>{' '}
          se mettent à jour seuls. Une variable paramètre un comportement existant ; elle n’en crée pas.
        </span>
      </div>

      {groups.map(({ group, variables }) => (
        <Card key={group} padding="none" title={`${group}.*`} subtitle={`${variables.length} variable(s)`}>
          <div>
            {variables.map((v) => (
              <div
                key={v.key}
                style={{
                  display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 110px 220px',
                  alignItems: 'center', gap: 'var(--space-4)',
                  padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)',
                }}
              >
                <div>
                  <div style={{ font: 'var(--type-label)' }}>{v.label}</div>
                  <div className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginTop: 2 }}>
                    {v.key}
                  </div>
                  {v.hint ? (
                    <div style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginTop: 3 }}>{v.hint}</div>
                  ) : null}
                </div>
                <Badge tone="neutral" size="sm"><span className="fb-num">{v.type}</span></Badge>
                <div className="fb-num" style={{ font: 'var(--type-data)', color: 'var(--text-secondary)' }}>
                  défaut : {describeDefault(v)}
                </div>
              </div>
            ))}
          </div>
        </Card>
      ))}
    </div>
  );
}

function describeDefault(v: Variable): string {
  const value = v.default as unknown;
  if (value === null) return '—';
  if (Array.isArray(value)) return value.length ? value.join(', ') : '[]';
  return String(value);
}

const infoStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
  padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-card)',
  background: 'var(--surface-info-subtle)', border: '1px solid var(--blue-500)', color: 'var(--blue-700)',
};
