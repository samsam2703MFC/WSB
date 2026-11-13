'use client';

import React from 'react';
import { Icon } from '../core/Icon';
import { IconButton } from '../core/IconButton';
import { Input } from '../forms/Input';

export interface DataTableColumn<T> {
  /** Field name in the row object, and the sort key sent to the API. */
  key: string;
  /** Uppercase header label (already translated). */
  label: string;
  /** 'right' for money, counts and dates; otherwise start-aligned. */
  align?: 'start' | 'right';
  /** Fixed column width, e.g. 120 or '18%'. */
  width?: number | string;
  /** Makes the header clickable and shows the sort chevron. */
  sortable?: boolean;
  /** Custom cell renderer — return a Badge, Switch, IconButton row, etc. */
  render?: (row: T) => React.ReactNode;
}

export interface DataTableSort { key: string; dir: 'asc' | 'desc' }

/** The back office's single table pattern: search, sort, pagination, row click-through. */
export interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  rows: T[];
  /** Unique row field; defaults to "id". */
  keyField?: string;
  searchable?: boolean;
  searchPlaceholder?: string;
  searchValue?: string;
  onSearch?: (value: string) => void;
  sort?: DataTableSort;
  onSort?: (sort: DataTableSort) => void;
  page?: number;
  pageCount?: number;
  /** Total row count from the API, shown in the footer. */
  total?: number;
  onPage?: (page: number) => void;
  /** Right side of the toolbar — usually a "Nouveau" Button and filters. */
  toolbar?: React.ReactNode;
  emptyLabel?: string;
  onRowClick?: (row: T) => void;
  style?: React.CSSProperties;
}

/* The back office's one table: search, sort, pagination, row actions.
   Columns declare their own alignment and renderer; the table never formats data. */
export function DataTable<T extends Record<string, unknown>>({
  columns = [], rows = [], keyField = 'id', searchable = true, searchPlaceholder = 'Rechercher',
  searchValue, onSearch, sort, onSort, page = 1, pageCount = 1, total, onPage,
  toolbar, emptyLabel = 'Aucun résultat', onRowClick, style, ...rest
}: DataTableProps<T>) {
  const [hover, setHover] = React.useState<unknown>(null);
  return (
    <div {...rest} style={{
      display: 'flex', flexDirection: 'column',
      background: 'var(--surface-card)', border: '1px solid var(--border-default)',
      borderRadius: 'var(--radius-card)', boxShadow: 'var(--shadow-sm)', overflow: 'hidden', ...style,
    }}>
      {(searchable || toolbar) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-4) var(--space-5)', borderBottom: '1px solid var(--border-subtle)' }}>
          {searchable && (
            <Input size="sm" iconLeft="search" placeholder={searchPlaceholder} value={searchValue} onChange={(e) => onSearch && onSearch(e.target.value)} wrapperStyle={{ width: 260 }} />
          )}
          {toolbar && <div style={{ marginInlineStart: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>{toolbar}</div>}
        </div>
      )}
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {columns.map((c) => {
                const sorted = Boolean(sort && sort.key === c.key);
                return (
                  <th
                    key={c.key}
                    aria-sort={sorted ? (sort!.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                    onClick={c.sortable && onSort ? () => onSort({ key: c.key, dir: sorted && sort!.dir === 'asc' ? 'desc' : 'asc' }) : undefined}
                    style={{
                      textAlign: c.align === 'right' ? 'right' : 'start',
                      font: 'var(--weight-semibold) var(--text-xs)/1 var(--font-sans)',
                      letterSpacing: 'var(--tracking-wide)', textTransform: 'uppercase',
                      color: sorted ? 'var(--text-brand)' : 'var(--text-tertiary)',
                      padding: 'var(--space-3) var(--space-4)', background: 'var(--slate-25)',
                      borderBottom: '1px solid var(--border-default)', whiteSpace: 'nowrap',
                      width: c.width, cursor: c.sortable && onSort ? 'pointer' : 'default', userSelect: 'none',
                    }}
                  >
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                      {c.label}
                      {c.sortable && <Icon name={sorted ? (sort!.dir === 'asc' ? 'chevron-down' : 'chevron-right') : 'arrow-up-down'} size={12} />}
                    </span>
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr><td colSpan={columns.length} style={{ padding: 'var(--space-10)', textAlign: 'center', font: 'var(--type-body)', color: 'var(--text-tertiary)' }}>{emptyLabel}</td></tr>
            )}
            {rows.map((r) => {
              const rowKey = r[keyField] as React.Key;
              return (
                <tr
                  key={rowKey}
                  onMouseEnter={() => setHover(rowKey)}
                  onMouseLeave={() => setHover(null)}
                  onClick={onRowClick ? () => onRowClick(r) : undefined}
                  style={{
                    background: hover === rowKey ? 'var(--surface-hover)' : 'transparent',
                    cursor: onRowClick ? 'pointer' : 'default',
                    transition: 'background-color var(--duration-fast) var(--ease-standard)',
                  }}
                >
                  {columns.map((c) => (
                    <td key={c.key} style={{
                      font: 'var(--type-body)', padding: 'var(--space-3) var(--space-4)',
                      borderBottom: '1px solid var(--border-subtle)',
                      textAlign: c.align === 'right' ? 'right' : 'start', verticalAlign: 'middle',
                    }}>
                      {c.render ? c.render(r) : (r[c.key] as React.ReactNode)}
                    </td>
                  ))}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {(pageCount > 1 || total != null) && (
        <div style={{
          display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-5)',
          borderTop: '1px solid var(--border-subtle)', background: 'var(--slate-25)',
          font: 'var(--type-body-sm)', color: 'var(--text-secondary)',
        }}>
          <span>{total != null ? total + ' entrées' : ''}</span>
          <div style={{ marginInlineStart: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <IconButton icon="chevron-left" label="Page précédente" size="sm" variant="secondary" disabled={page <= 1} onClick={() => onPage && onPage(page - 1)} />
            <span className="fb-num">{page} / {pageCount}</span>
            <IconButton icon="chevron-right" label="Page suivante" size="sm" variant="secondary" disabled={page >= pageCount} onClick={() => onPage && onPage(page + 1)} />
          </div>
        </div>
      )}
    </div>
  );
}
