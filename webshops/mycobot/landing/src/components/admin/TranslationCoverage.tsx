import { Card, ProgressBar } from '@/design-system';

export interface CoverageRow {
  code: string;
  name: string;
  isRtl: boolean;
  coverage: number;
}

/**
 * Translation coverage per locale: how much of the total field set each language
 * actually has a row for. Anything under 100% falls back to French on the landing.
 */
export function TranslationCoverage({ languages }: { languages: CoverageRow[] }) {
  return (
    <Card title="Couverture des traductions" subtitle="8 langues · fallback français" padding="md">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
        {languages.map((l) => (
          <div key={l.code} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
            <span className="fb-num" style={{ width: 28, font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
              {l.code.toUpperCase()}
            </span>
            <span style={{ width: 96, font: 'var(--type-body-sm)', fontFamily: l.isRtl ? 'var(--font-arabic)' : undefined }}>
              {l.name}
            </span>
            <ProgressBar
              value={l.coverage}
              size="sm"
              tone={l.coverage >= 90 ? 'success' : l.coverage >= 70 ? 'warning' : 'danger'}
              valueLabel={`${l.coverage}%`}
              style={{ flex: 1 }}
            />
          </div>
        ))}
      </div>
    </Card>
  );
}
