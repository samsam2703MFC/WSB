'use client';

import React from 'react';
import { Icon } from '../core/Icon';
import { Button } from '../core/Button';

export interface FileDropItem {
  /** Display name. */
  name?: string;
  /** Relative server path returned by the upload endpoint, e.g. "/storage/screenshots/shop-1.png". */
  path?: string;
  /** Pre-formatted size, e.g. "1,2 Mo". */
  size?: string;
}

/**
 * Drag-and-drop upload zone for brand logos and module screenshots. The endpoint writes to
 * <STORAGE_PATH>/<category>/ and stores only the relative path in the DB.
 */
export interface FileDropProps {
  label?: string;
  /** Constraint line; defaults to "PNG ou JPG, 4 Mo maximum". */
  hint?: string;
  accept?: string;
  multiple?: boolean;
  /** Already-uploaded files, listed under the zone. */
  files?: FileDropItem[];
  onFiles?: (files: File[]) => void;
  onRemove?: (file: FileDropItem, index: number) => void;
  /** Shown in mono inside the zone, e.g. "/storage/screenshots/". */
  storagePath?: string;
  disabled?: boolean;
  style?: React.CSSProperties;
}

export function FileDrop({
  label, hint = 'PNG ou JPG, 4 Mo maximum', accept = 'image/png,image/jpeg', multiple = true,
  files = [], onFiles, onRemove, storagePath, disabled = false, style, ...rest
}: FileDropProps) {
  const [over, setOver] = React.useState(false);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const pick = () => inputRef.current?.click();
  return (
    <div {...rest} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', minWidth: 0, ...style }}>
      {label && <span style={{ font: 'var(--type-label)', color: 'var(--text-primary)' }}>{label}</span>}
      <div
        onDragOver={(e) => { e.preventDefault(); setOver(true); }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); if (onFiles) onFiles(Array.from(e.dataTransfer.files)); }}
        onClick={disabled ? undefined : pick}
        style={{
          display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--space-2)',
          padding: 'var(--space-6)', textAlign: 'center', cursor: disabled ? 'not-allowed' : 'pointer',
          borderRadius: 'var(--radius-card)',
          border: '1px dashed ' + (over ? 'var(--brand)' : 'var(--border-strong)'),
          background: over ? 'var(--surface-brand-subtle)' : 'var(--slate-25)',
          transition: 'var(--transition-control)', opacity: disabled ? 0.6 : 1,
        }}
      >
        <Icon name="upload" size={20} color={over ? 'var(--text-brand)' : 'var(--text-tertiary)'} />
        <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>
          Glissez vos fichiers ou <span style={{ color: 'var(--text-link)' }}>parcourez</span>
        </span>
        <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{hint}</span>
        {storagePath && <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{storagePath}</span>}
        <input
          ref={inputRef}
          type="file"
          accept={accept}
          multiple={multiple}
          disabled={disabled}
          onChange={(e) => onFiles && onFiles(Array.from(e.target.files || []))}
          style={{ display: 'none' }}
        />
      </div>
      {files.length > 0 && (
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
          {files.map((f, i) => (
            <li key={f.path || f.name || i} style={{
              display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)',
              border: '1px solid var(--border-default)', borderRadius: 'var(--radius-md)', background: 'var(--surface-card)',
            }}>
              <Icon name="image" size={15} color="var(--text-tertiary)" />
              <span style={{ font: 'var(--type-body-sm)', flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.name || f.path}</span>
              {f.size && <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{f.size}</span>}
              {onRemove && <Button variant="ghost" size="sm" iconLeft="trash-2" onClick={() => onRemove(f, i)}>Retirer</Button>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
