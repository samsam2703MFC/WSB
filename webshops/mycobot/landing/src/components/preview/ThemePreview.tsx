'use client';

import React from 'react';
import {
  Badge, Button, Card, Carousel, Checkbox, DataTable, Dialog, FileDrop, Icon, IconButton,
  Input, LanguageSwitcher, ModuleCard, PlanCard, ProgressBar, Radio, Select, StatTile,
  Switch, Tabs, Tag, Textarea, Toast, Tooltip,
  type DataTableColumn,
} from '@/design-system';
import type { ThemeAssets } from '@/lib/config/theme';

/**
 * One client's whole interface on one page.
 *
 * Nothing here is styled for the preview: every component is the one the product
 * ships, and the client's theme reaches them the only way it ever does — as custom
 * properties on a wrapper. If a colour reads badly here, it reads badly in the app,
 * which is the point of handing this link over before anything is built.
 */

export interface ThemePreviewProps {
  tenantName: string;
  slug: string;
  version: number;
  draft: boolean;
  updatedAt: string | null;
  css: string;
  assets: ThemeAssets;
  swatches: { key: string; label: string; value: string }[];
  overrides: number;
  icons: string[];
  resolutions: { key: string; from: 'tenant' | 'global' | 'registry' }[];
}

const SECTIONS = [
  { id: 'couleurs', label: 'Couleurs', icon: 'image' },
  { id: 'typographie', label: 'Typographie', icon: 'file-text' },
  { id: 'boutons', label: 'Boutons', icon: 'check' },
  { id: 'formulaires', label: 'Formulaires & listes', icon: 'list-checks' },
  { id: 'navigation', label: 'Navigation', icon: 'panels-top-left' },
  { id: 'contenu', label: 'Contenu', icon: 'layers' },
  { id: 'donnees', label: 'Données', icon: 'chart-line' },
  { id: 'retours', label: 'Retours', icon: 'bell' },
  { id: 'icones', label: 'Icônes', icon: 'sparkles' },
];

const BUTTON_VARIANTS = ['primary', 'warm', 'secondary', 'subtle', 'ghost', 'danger'] as const;
const BADGE_TONES = ['neutral', 'brand', 'warm', 'success', 'warning', 'danger', 'info'] as const;

export function ThemePreview(props: ThemePreviewProps) {
  const { tenantName, slug, version, draft, css, assets, swatches, overrides, icons, resolutions } = props;

  const [tab, setTab] = React.useState('apercu');
  const [segmented, setSegmented] = React.useState('semaine');
  const [locale, setLocale] = React.useState('fr');
  const [pick, setPick] = React.useState('sklep');
  const [loyalty, setLoyalty] = React.useState(true);
  const [offline, setOffline] = React.useState(false);
  const [terms, setTerms] = React.useState(true);
  const [plan, setPlan] = React.useState('mensuel');
  const [dialog, setDialog] = React.useState(false);
  const [toast, setToast] = React.useState(true);
  const [note, setNote] = React.useState('Livraison décalée à 14 h — prévenir le magasin de Lyon.');

  const tenantOverrides = new Set(resolutions.filter((r) => r.from === 'tenant').map((r) => r.key));

  return (
    <>
      {/* The client's theme reaches every component below as custom properties. No
          per-tenant stylesheet is built, shipped or stored. */}
      <style dangerouslySetInnerHTML={{ __html: `.tfb-theme {\n  ${css}\n}` }} />

      <div className="tfb-theme" style={{ background: 'var(--surface-page)', color: 'var(--text-primary)', minHeight: '100vh', font: 'var(--type-body)' }}>
        <header style={headerStyle}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', flexWrap: 'wrap' }}>
            {assets.logo ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={assets.logo} alt={tenantName} style={{ height: 40, width: 'auto' }} />
            ) : (
              <span style={logoFallbackStyle}>{initials(tenantName)}</span>
            )}
            <div style={{ minWidth: 0 }}>
              <h1 style={{ font: 'var(--type-heading-3)', letterSpacing: 'var(--tracking-tight)' }}>{tenantName}</h1>
              <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)', marginTop: 2 }}>
                Charte appliquée — chaque élément ci-dessous est le composant réel du produit.
              </p>
            </div>
            <div style={{ marginInlineStart: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
              {draft ? (
                <Badge tone="warning" dot size="sm">Brouillon — non servi</Badge>
              ) : (
                <Badge tone="success" dot size="sm">Publié — <span className="fb-num">v{version}</span></Badge>
              )}
              <Badge tone="neutral" size="sm">{overrides} personnalisation(s)</Badge>
              <Badge tone="neutral" size="sm"><span className="fb-num">{slug}</span></Badge>
            </div>
          </div>

          <nav style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap', marginTop: 'var(--space-4)' }}>
            {SECTIONS.map((s) => (
              <a key={s.id} href={`#${s.id}`} style={anchorStyle}>
                <Icon name={s.icon} size={13} />
                {s.label}
              </a>
            ))}
          </nav>
        </header>

        <main style={mainStyle}>
          {/* ------------------------------------------------------- couleurs ---- */}
          <Section id="couleurs" title="Couleurs" note="Les valeurs viennent de la fiche client, pas d'une feuille de style.">
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: 'var(--space-3)' }}>
              {swatches.map((s) => (
                <div key={s.key} style={{ border: '1px solid var(--border-default)', borderRadius: 'var(--radius-card)', overflow: 'hidden', background: 'var(--surface-card)' }}>
                  <div style={{ height: 64, background: s.value }} />
                  <div style={{ padding: 'var(--space-3)' }}>
                    <div style={{ font: 'var(--type-label)' }}>{s.label}</div>
                    <div className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{s.value}</div>
                    {tenantOverrides.has(s.key) && <Badge tone="brand" size="sm" style={{ marginTop: 6 }}>personnalisée</Badge>}
                  </div>
                </div>
              ))}
            </div>
          </Section>

          {/* --------------------------------------------------- typographie ---- */}
          <Section id="typographie" title="Typographie">
            <Card padding="lg">
              <div style={{ font: 'var(--type-heading-1)' }}>Titre de page</div>
              <div style={{ font: 'var(--type-heading-3)', marginTop: 'var(--space-3)' }}>Titre de section</div>
              <div style={{ font: 'var(--type-title)', marginTop: 'var(--space-3)' }}>Titre de carte</div>
              <p style={{ font: 'var(--type-body)', marginTop: 'var(--space-3)', maxWidth: '66ch', color: 'var(--text-secondary)' }}>
                Texte courant. C’est la police de l’interface : listes de produits, formulaires de saisie,
                messages d’erreur. Elle doit rester lisible sur un écran de caisse comme sur un téléphone.
              </p>
              <p style={{ font: 'var(--type-body-sm)', marginTop: 'var(--space-2)', color: 'var(--text-tertiary)' }}>
                Texte secondaire — aides de saisie et légendes.
              </p>
              <p className="fb-num" style={{ font: 'var(--type-data)', marginTop: 'var(--space-3)' }}>
                1 234,56 € · SKU-40912 · 2026-08-02 — chiffres et identifiants, toujours en chasse fixe.
              </p>
            </Card>
          </Section>

          {/* ------------------------------------------------------- boutons ---- */}
          <Section id="boutons" title="Boutons" note="Toutes les variantes, tailles et états.">
            <Card padding="lg">
              <Label>Variantes</Label>
              <Row>
                {BUTTON_VARIANTS.map((variant) => (
                  <Button key={variant} variant={variant}>{variant}</Button>
                ))}
              </Row>

              <Label>Tailles</Label>
              <Row>
                <Button size="sm">Petit</Button>
                <Button size="md">Moyen</Button>
                <Button size="lg">Grand</Button>
                <Button size="lg" variant="warm" iconRight="arrow-right">Commander</Button>
              </Row>

              <Label>Avec icône, en cours, désactivé, pleine largeur</Label>
              <Row>
                <Button iconLeft="plus">Ajouter</Button>
                <Button iconRight="arrow-right" variant="secondary">Continuer</Button>
                <Button loading>Enregistrement</Button>
                <Button disabled>Indisponible</Button>
              </Row>
              <Button fullWidth variant="warm" iconLeft="shopping-cart" style={{ marginTop: 'var(--space-3)' }}>
                Valider la commande
              </Button>

              <Label>Boutons icône</Label>
              <Row>
                {(['ghost', 'secondary', 'primary'] as const).map((variant) => (
                  <IconButton key={variant} icon="pencil" label={`Modifier — ${variant}`} variant={variant} />
                ))}
                <IconButton icon="trash-2" label="Supprimer" variant="secondary" />
                <IconButton icon="search" label="Rechercher" active />
                <IconButton icon="download" label="Télécharger" disabled />
              </Row>
            </Card>
          </Section>

          {/* -------------------------------------------------- formulaires ---- */}
          <Section id="formulaires" title="Formulaires & listes déroulantes">
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(330px, 1fr))', gap: 'var(--space-4)' }}>
              <Card padding="lg" title="Champs">
                <Input label="Nom du point de vente" defaultValue="Paris Bastille" hint="Tel qu’il apparaît sur les tickets." />
                <Input label="Recherche" iconLeft="search" placeholder="Référence, EAN…" wrapperStyle={{ marginTop: 'var(--space-3)' }} />
                <Input label="Prix de vente" defaultValue="14,90" suffix="€" wrapperStyle={{ marginTop: 'var(--space-3)' }} />
                <Input label="Remise" defaultValue="120" error="Une remise ne peut pas dépasser 100 %." suffix="%" wrapperStyle={{ marginTop: 'var(--space-3)' }} />
                <Input label="Champ désactivé" defaultValue="Verrouillé par le siège" disabled wrapperStyle={{ marginTop: 'var(--space-3)' }} />
                <Textarea
                  label="Note interne"
                  rows={3}
                  value={note}
                  maxLength={280}
                  showCount
                  onChange={(e) => setNote(e.target.value)}
                  wrapperStyle={{ marginTop: 'var(--space-3)' }}
                />
              </Card>

              <Card padding="lg" title="Listes déroulantes et choix">
                <Select
                  label="Module principal"
                  options={[
                    { value: 'sklep', label: 'Boutique et caisse' },
                    { value: 'admin', label: 'Back office réseau' },
                    { value: 'core', label: 'Socle' },
                  ]}
                  value={pick}
                  onChange={(e) => setPick(e.target.value)}
                />
                <Select
                  label="Devise"
                  options={['EUR', 'CHF', 'GBP']}
                  size="sm"
                  defaultValue="EUR"
                  wrapperStyle={{ marginTop: 'var(--space-3)' }}
                />
                <Select
                  label="Entrepôt"
                  options={['Nord', 'Sud']}
                  error="Aucun entrepôt rattaché à ce magasin."
                  wrapperStyle={{ marginTop: 'var(--space-3)' }}
                />
                <Select label="Liste désactivée" options={['—']} disabled wrapperStyle={{ marginTop: 'var(--space-3)' }} />

                <div style={{ marginTop: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                  <Switch label="Programme de fidélité" description="Cumul de points sur chaque ticket." checked={loyalty} onChange={setLoyalty} />
                  <Switch label="Mode hors-ligne étendu" checked={offline} onChange={setOffline} />
                  <Switch label="Verrouillé par le siège" checked disabled />
                </div>

                <div style={{ marginTop: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                  <Checkbox label="J’accepte les conditions" checked={terms} onChange={setTerms} />
                  <Checkbox label="Sélection partielle" indeterminate />
                  <Checkbox label="Option indisponible" disabled />
                </div>

                <div style={{ marginTop: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                  <Radio name="facturation" value="mensuel" label="Mensuel" checked={plan === 'mensuel'} onChange={() => setPlan('mensuel')} />
                  <Radio name="facturation" value="annuel" label="Annuel" description="Deux mois offerts." checked={plan === 'annuel'} onChange={() => setPlan('annuel')} />
                </div>
              </Card>

              <Card padding="lg" title="Dépôt de fichier">
                <FileDrop
                  label="Logo de l’enseigne"
                  hint="PNG ou SVG, 2 Mo maximum"
                  storagePath="/storage/brands/"
                  files={assets.logo ? [{ name: assets.logo.split('/').pop(), path: assets.logo }] : []}
                />
              </Card>
            </div>
          </Section>

          {/* --------------------------------------------------- navigation ---- */}
          <Section id="navigation" title="Navigation">
            <Card padding="lg">
              <Label>Onglets</Label>
              <Tabs
                items={[
                  { value: 'apercu', label: 'Aperçu', icon: 'layout-dashboard' },
                  { value: 'stock', label: 'Stock', icon: 'shopping-cart', count: 12 },
                  { value: 'equipe', label: 'Équipe', icon: 'users' },
                ]}
                value={tab}
                onChange={setTab}
              />
              <div style={{ marginTop: 'var(--space-4)' }}>
                <Label>Segmenté</Label>
                <Tabs
                  variant="segmented"
                  items={[
                    { value: 'jour', label: 'Jour' },
                    { value: 'semaine', label: 'Semaine' },
                    { value: 'mois', label: 'Mois' },
                  ]}
                  value={segmented}
                  onChange={setSegmented}
                />
              </div>
              <div style={{ marginTop: 'var(--space-4)' }}>
                <Label>Langue</Label>
                <LanguageSwitcher value={locale} onChange={(code) => setLocale(code)} />
              </div>
              <div style={{ marginTop: 'var(--space-4)' }}>
                <Label>Étiquettes</Label>
                <Row>
                  <Tag icon="tag">Promotion</Tag>
                  <Tag selected>Sélectionnée</Tag>
                  <Tag icon="truck" interactive>Livraison</Tag>
                  <Tag icon="star">Fidélité</Tag>
                </Row>
              </div>
            </Card>
          </Section>

          {/* ------------------------------------------------------ contenu ---- */}
          <Section id="contenu" title="Contenu">
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 'var(--space-3)', marginBottom: 'var(--space-4)' }}>
              <StatTile label="Chiffre d’affaires" value="418 k€" delta="+6,4 %" deltaLabel="vs. juillet" trend="up" icon="chart-line" />
              <StatTile label="Tickets" value="12 480" unit="ce mois" icon="receipt" />
              <StatTile label="Ruptures" value="7" delta="−3" deltaLabel="cette semaine" trend="down" icon="triangle-alert" />
              <StatTile label="Points de vente" value="34" icon="store" tone="inverse" />
            </div>

            <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap', marginBottom: 'var(--space-4)' }}>
              {BADGE_TONES.map((tone) => (
                <Badge key={tone} tone={tone} dot>{tone}</Badge>
              ))}
              <Badge tone="success" icon="circle-check">payé</Badge>
              <Badge tone="danger" icon="triangle-alert">rupture</Badge>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 'var(--space-4)' }}>
              <Card padding="lg" title="Carte simple" subtitle="Sous-titre" footer="Pied de carte">
                <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>
                  Le conteneur par défaut de l’interface.
                </p>
                <ProgressBar label="Onboarding" value={6} max={9} valueLabel="6 / 9 étapes" style={{ marginTop: 'var(--space-4)' }} />
                <ProgressBar label="Stock critique" value={82} tone="danger" size="sm" style={{ marginTop: 'var(--space-3)' }} />
              </Card>
              <Card padding="lg" tone="sunken" title="Carte creusée">
                <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>Pour les zones secondaires.</p>
              </Card>
              <Card padding="lg" tone="brand" title="Carte de marque">
                <p style={{ font: 'var(--type-body-sm)' }}>Teintée de la couleur primaire du client.</p>
              </Card>
              <Card padding="lg" tone="inverse" title="Carte inversée">
                <p style={{ font: 'var(--type-body-sm)' }}>Fond sombre, pour les en-têtes.</p>
              </Card>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 'var(--space-4)', marginTop: 'var(--space-4)' }}>
              <ModuleCard
                icon="shopping-cart"
                name="Boutique & caisse"
                group="Ventes"
                description="Encaissement, retours et clôture de caisse sur tablette."
                isNew
                screenshots={[{ alt: 'Écran de caisse' }, { alt: 'Clôture' }]}
                placeholderPath="/storage/screenshots/"
              />
              <PlanCard
                name="Réseau"
                priceLabel="129 €"
                intervalLabel="/ mois par point de vente"
                description="Pour les enseignes de 10 à 49 magasins."
                features={['Caisse et stock', 'Back office réseau', 'Support prioritaire']}
                featured
              />
              <Card padding="none" title="Carrousel">
                <Carousel items={[{ alt: 'Capture 1' }, { alt: 'Capture 2' }, { alt: 'Capture 3' }]} placeholderPath="/storage/screenshots/" />
              </Card>
            </div>
          </Section>

          {/* ------------------------------------------------------ données ---- */}
          <Section id="donnees" title="Données">
            <Card padding="none">
              <DataTable columns={TABLE_COLUMNS} rows={TABLE_ROWS} searchable searchPlaceholder="Filtrer un magasin…" />
            </Card>
          </Section>

          {/* ------------------------------------------------------ retours ---- */}
          <Section id="retours" title="Retours utilisateur">
            <Card padding="lg">
              <Label>Notifications</Label>
              <div style={{ display: 'grid', gap: 'var(--space-3)', maxWidth: 560 }}>
                <Toast tone="success" title="Commande enregistrée" message="Le magasin de Lyon a été prévenu." />
                <Toast tone="warning" title="Stock bas" message="7 références sous le seuil d’alerte." />
                <Toast tone="danger" title="Paiement refusé" message="La licence passera en past_due dans 3 jours." />
                {toast && <Toast tone="info" title="Nouvelle version" message="Rechargez pour l’appliquer." onDismiss={() => setToast(false)} />}
              </div>

              <Label>Infobulle et boîte de dialogue</Label>
              <Row>
                <Tooltip content="Exporter en CSV">
                  <IconButton icon="download" label="Exporter" variant="secondary" />
                </Tooltip>
                <Button variant="secondary" onClick={() => setDialog(true)}>Ouvrir une boîte de dialogue</Button>
              </Row>

              <div style={{ position: 'relative', minHeight: dialog ? 340 : 0, marginTop: dialog ? 'var(--space-4)' : 0, borderRadius: 'var(--radius-card)', overflow: 'hidden' }}>
                <Dialog
                  open={dialog}
                  tone="warning"
                  title="Bloquer la licence ?"
                  description="Le point de vente perdra l’accès à la caisse immédiatement."
                  onClose={() => setDialog(false)}
                  footer={
                    <>
                      <Button variant="secondary" onClick={() => setDialog(false)}>Annuler</Button>
                      <Button variant="danger" onClick={() => setDialog(false)}>Bloquer</Button>
                    </>
                  }
                >
                  <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>
                    Cette action est enregistrée dans l’historique des licences.
                  </p>
                </Dialog>
              </div>
            </Card>
          </Section>

          {/* ------------------------------------------------------- icônes ---- */}
          <Section id="icones" title={`Icônes — ${icons.length} glyphes`} note="Aucune icône n'est dessinée à la main ; tout vient de public/icons.">
            <Card padding="lg">
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(112px, 1fr))', gap: 'var(--space-3)' }}>
                {icons.map((name) => (
                  <div key={name} style={iconCellStyle}>
                    <Icon name={name} size={20} color="var(--text-primary)" />
                    <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', textAlign: 'center', wordBreak: 'break-all' }}>
                      {name}
                    </span>
                  </div>
                ))}
              </div>
            </Card>
          </Section>

          <footer style={{ padding: 'var(--space-6) 0', color: 'var(--text-tertiary)', font: 'var(--type-caption)' }}>
            Page générée depuis la fiche client — thème {draft ? 'en brouillon' : `publié v${version}`}. Aucune donnée
            client n’y figure.
          </footer>
        </main>
      </div>
    </>
  );
}

/* ------------------------------------------------------------------- bits ---- */

interface StoreRow {
  /** DataTable rows are indexed by column key, so the shape has to admit it. */
  [key: string]: unknown;
  id: string;
  magasin: string;
  ville: string;
  ca: string;
  statut: 'active' | 'past_due' | 'blocked';
}

const TABLE_ROWS: StoreRow[] = [
  { id: 's-1', magasin: 'Paris Bastille', ville: 'Paris', ca: '48 200 €', statut: 'active' },
  { id: 's-2', magasin: 'Lyon Part-Dieu', ville: 'Lyon', ca: '39 940 €', statut: 'active' },
  { id: 's-3', magasin: 'Lille Centre', ville: 'Lille', ca: '21 130 €', statut: 'past_due' },
  { id: 's-4', magasin: 'Marseille Vieux-Port', ville: 'Marseille', ca: '17 480 €', statut: 'blocked' },
];

const TABLE_COLUMNS: DataTableColumn<StoreRow>[] = [
  { key: 'magasin', label: 'Magasin', sortable: true },
  { key: 'ville', label: 'Ville' },
  { key: 'ca', label: 'CA du mois', align: 'right', sortable: true, render: (r) => <span className="fb-num">{r.ca}</span> },
  {
    key: 'statut',
    label: 'Statut',
    render: (r) => (
      <Badge tone={r.statut === 'active' ? 'success' : r.statut === 'past_due' ? 'warning' : 'danger'} dot size="sm">
        <span className="fb-num">{r.statut}</span>
      </Badge>
    ),
  },
];

function Section({ id, title, note, children }: { id: string; title: string; note?: string; children: React.ReactNode }) {
  return (
    <section id={id} style={{ scrollMarginTop: 'var(--space-6)', marginBottom: 'var(--space-8)' }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-3)', marginBottom: 'var(--space-3)', flexWrap: 'wrap' }}>
        <h2 style={{ font: 'var(--type-heading-4)' }}>{title}</h2>
        {note && <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{note}</span>}
      </div>
      {children}
    </section>
  );
}

function Label({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ font: 'var(--type-caption)', letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--text-tertiary)', margin: 'var(--space-4) 0 var(--space-2)' }}>
      {children}
    </div>
  );
}

function Row({ children }: { children: React.ReactNode }) {
  return <div style={{ display: 'flex', gap: 'var(--space-3)', flexWrap: 'wrap', alignItems: 'center' }}>{children}</div>;
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0] ?? '')
    .join('')
    .toUpperCase();
}

const headerStyle: React.CSSProperties = {
  padding: 'var(--space-6) var(--space-6) var(--space-4)',
  background: 'var(--surface-card)',
  borderBottom: '1px solid var(--border-default)',
};

const mainStyle: React.CSSProperties = {
  padding: 'var(--space-6)',
  maxWidth: 1280,
  margin: '0 auto',
};

const logoFallbackStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
  width: 44, height: 44, flex: 'none', borderRadius: 'var(--radius-card)',
  background: 'var(--brand)', color: 'var(--brand-on)', font: 'var(--type-title)',
};

const anchorStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: 6,
  padding: '5px 10px', borderRadius: 'var(--radius-chip)',
  border: '1px solid var(--border-default)', background: 'var(--surface-card)',
  color: 'var(--text-secondary)', font: 'var(--type-caption)', textDecoration: 'none',
};

const iconCellStyle: React.CSSProperties = {
  display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6,
  padding: 'var(--space-3) var(--space-2)',
  border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-md)',
};
