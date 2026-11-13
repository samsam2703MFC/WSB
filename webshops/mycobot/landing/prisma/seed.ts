/**
 * Seeds tfb_landing: the 8 languages, the landing sections, 6 brands, 8 modules
 * with placeholder screenshot rows, 3 plans, one superadmin, and every FR/EN/AR
 * translation.
 *
 * Idempotent — upserts on the natural keys, so it is safe to re-run after content
 * edits in the back office (translation rows for authored locales are overwritten).
 *
 *   npm run seed
 */
import { PrismaClient } from '@prisma/client';
import { hashPassword } from '../src/lib/auth/password';
import {
  AUTHORED_LOCALES, LANGUAGES, SECTIONS, BRANDS, MODULES, PLANS,
  UI_STRINGS, MODULE_STRINGS, PLAN_STRINGS, CONTACT_MESSAGES,
} from './seed-content';

const prisma = new PrismaClient();
const LIST_SEPARATOR = '|';

type Row = { entityType: string; entityId: number; field: string; languageCode: string; value: string };

async function upsertTranslations(rows: Row[]) {
  // createMany + skipDuplicates would leave stale values behind, so upsert on the
  // unique scope. Chunked to keep the transaction small on a remote MySQL.
  const CHUNK = 200;
  for (let i = 0; i < rows.length; i += CHUNK) {
    const chunk = rows.slice(i, i + CHUNK);
    await prisma.$transaction(
      chunk.map((r) =>
        prisma.translation.upsert({
          where: {
            entityType_entityId_field_languageCode: {
              entityType: r.entityType,
              entityId: r.entityId,
              field: r.field,
              languageCode: r.languageCode,
            },
          },
          create: r,
          update: { value: r.value },
        }),
      ),
    );
  }
}

async function main() {
  // ---- Languages -----------------------------------------------------------
  for (const l of LANGUAGES) {
    await prisma.language.upsert({ where: { code: l.code }, create: l, update: l });
  }
  console.log(`languages: ${LANGUAGES.length}`);

  // ---- Sections ------------------------------------------------------------
  for (const s of SECTIONS) {
    await prisma.section.upsert({ where: { key: s.key }, create: s, update: s });
  }
  console.log(`sections: ${SECTIONS.length}`);

  // ---- Brands --------------------------------------------------------------
  // No tfb_brands.logo_path files were supplied — the strip renders name chips
  // until logos are uploaded through the back office.
  const brandIds = new Map<string, number>();
  for (const b of BRANDS) {
    const row = await prisma.brand.upsert({
      where: { slug: b.slug },
      create: { slug: b.slug, name: b.name, sortOrder: b.sortOrder, isActive: true },
      update: { name: b.name, sortOrder: b.sortOrder },
    });
    brandIds.set(b.slug, row.id);
  }
  console.log(`brands: ${BRANDS.length}`);

  // ---- Modules + screenshot placeholders -----------------------------------
  const moduleIds = new Map<string, number>();
  const screenshotIds = new Map<string, number[]>();
  for (const m of MODULES) {
    const row = await prisma.module.upsert({
      where: { key: m.key },
      create: {
        key: m.key, slug: m.slug, icon: m.icon, moduleGroup: m.moduleGroup, repo: m.repo,
        redirectUrl: `/modules/${m.slug}`, isNew: m.isNew, isActive: true, sortOrder: m.sortOrder,
      },
      update: {
        slug: m.slug, icon: m.icon, moduleGroup: m.moduleGroup, repo: m.repo,
        redirectUrl: `/modules/${m.slug}`, isNew: m.isNew, sortOrder: m.sortOrder,
      },
    });
    moduleIds.set(m.key, row.id);

    // One row per carousel frame. file_path points at where the morning routine
    // will drop the real capture; the Carousel shows a labelled placeholder until
    // the file exists.
    const ids: number[] = [];
    const existing = await prisma.moduleScreenshot.findMany({ where: { moduleId: row.id }, orderBy: { sortOrder: 'asc' } });
    for (let i = 0; i < m.shots; i += 1) {
      const filePath = `/storage/screenshots/${m.key}-${i + 1}.png`;
      const found = existing.find((s) => s.filePath === filePath);
      if (found) { ids.push(found.id); continue; }
      const created = await prisma.moduleScreenshot.create({
        data: { moduleId: row.id, filePath, sortOrder: i + 1 },
      });
      ids.push(created.id);
    }
    screenshotIds.set(m.key, ids);
  }
  console.log(`modules: ${MODULES.length}`);

  // ---- Plans ---------------------------------------------------------------
  const planIds = new Map<string, number>();
  for (const p of PLANS) {
    const row = await prisma.plan.upsert({
      where: { key: p.key },
      create: { ...p, isActive: true },
      update: { amount: p.amount, currency: p.currency, interval: p.interval, isFeatured: p.isFeatured, sortOrder: p.sortOrder },
    });
    planIds.set(p.key, row.id);
  }
  console.log(`plans: ${PLANS.length}`);

  // ---- Translations --------------------------------------------------------
  const rows: Row[] = [];

  for (const locale of AUTHORED_LOCALES) {
    for (const [key, value] of Object.entries(UI_STRINGS[locale])) {
      rows.push({ entityType: 'ui', entityId: 0, field: key, languageCode: locale, value });
    }

    for (const [moduleKey, copy] of Object.entries(MODULE_STRINGS[locale])) {
      const id = moduleIds.get(moduleKey);
      if (!id) continue;
      rows.push({ entityType: 'module', entityId: id, field: 'name', languageCode: locale, value: copy.name });
      rows.push({ entityType: 'module', entityId: id, field: 'description', languageCode: locale, value: copy.description });
      if (copy.bullets) rows.push({ entityType: 'module', entityId: id, field: 'bullets', languageCode: locale, value: copy.bullets.join(LIST_SEPARATOR) });
      if (copy.metric) {
        rows.push({ entityType: 'module', entityId: id, field: 'metric_value', languageCode: locale, value: copy.metric[0] });
        rows.push({ entityType: 'module', entityId: id, field: 'metric_label', languageCode: locale, value: copy.metric[1] });
      }
    }

    for (const [planKey, copy] of Object.entries(PLAN_STRINGS[locale])) {
      const id = planIds.get(planKey);
      if (!id) continue;
      rows.push({ entityType: 'plan', entityId: id, field: 'name', languageCode: locale, value: copy.name });
      rows.push({ entityType: 'plan', entityId: id, field: 'description', languageCode: locale, value: copy.description });
      rows.push({ entityType: 'plan', entityId: id, field: 'features', languageCode: locale, value: copy.features.join(LIST_SEPARATOR) });
    }

    // Brand names are latin in every locale, but the row must exist so the editor
    // can override it (e.g. a transliterated Arabic name).
    for (const b of BRANDS) {
      const id = brandIds.get(b.slug);
      if (!id) continue;
      rows.push({ entityType: 'brand', entityId: id, field: 'name', languageCode: locale, value: b.name });
    }

    // Screenshot alt text — required for accessibility, translated per locale.
    const altPrefix = UI_STRINGS[locale]['shot.alt'];
    for (const m of MODULES) {
      const ids = screenshotIds.get(m.key) ?? [];
      ids.forEach((id, i) => {
        rows.push({ entityType: 'screenshot', entityId: id, field: 'alt', languageCode: locale, value: `${altPrefix} — ${m.key} ${i + 1}` });
      });
    }
  }

  await upsertTranslations(rows);
  console.log(`translations: ${rows.length} rows across ${AUTHORED_LOCALES.length} locales (nl/de/pl/uk/ru fall back to fr)`);

  // ---- Admin user ----------------------------------------------------------
  const adminEmail = process.env.SEED_ADMIN_EMAIL || 'admin@franchisebuddy.eu';
  const adminPassword = process.env.SEED_ADMIN_PASSWORD || 'changeme';
  await prisma.adminUser.upsert({
    where: { email: adminEmail },
    create: { email: adminEmail, passwordHash: await hashPassword(adminPassword), role: 'superadmin', isActive: true },
    update: {},
  });
  console.log(`admin: ${adminEmail}${process.env.SEED_ADMIN_PASSWORD ? '' : ' (password "changeme" — change it)'}`);

  // ---- Contact inbox -------------------------------------------------------
  if ((await prisma.contactMessage.count()) === 0) {
    await prisma.contactMessage.createMany({ data: CONTACT_MESSAGES });
    console.log(`contact messages: ${CONTACT_MESSAGES.length}`);
  }

  // ---- Settings ------------------------------------------------------------
  await prisma.setting.upsert({
    where: { key: 'landing.contact_email' },
    create: { key: 'landing.contact_email', value: { email: 'contact@franchisebuddy.eu' } },
    update: {},
  });
}

main()
  .then(() => prisma.$disconnect())
  .catch(async (e) => {
    console.error(e);
    await prisma.$disconnect();
    process.exit(1);
  });
