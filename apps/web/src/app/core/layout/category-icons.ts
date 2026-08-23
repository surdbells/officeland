import type { IconName } from '../../shared/icon';

/**
 * An icon for every subcategory in the megamenu.
 *
 * ── Why the map lives here and not in the database ───────────────────────
 * An `icon` column would let the admin change one without a deploy, and that
 * is a real advantage. It also lets the admin type a name the front end has
 * never heard of, which under the closed `IconName` union is a build-time
 * error moved to runtime — a menu item silently missing its icon in
 * production. The choice of glyph is presentation, changes about as often as
 * the taxonomy itself, and belongs where the type system can see it.
 *
 * ── Keyed by slug, not by path ───────────────────────────────────────────
 * Subcategory slugs are unique across the tree today and the seeder keeps
 * them that way. Slugs also survive a subcategory moving between departments,
 * which paths do not.
 *
 * ── Missing entries are visible, not silent ──────────────────────────────
 * `iconForCategory` falls back to `chevron-right` so a new subcategory looks
 * plain rather than broken. That fallback is a safety net, not the policy:
 * `npm run gate:icons` fails the build when a subcategory in
 * docs/TAXONOMY.md has no entry here, so the net should never be needed.
 */
const CATEGORY_ICONS = {
  // Office Furniture
  seating: 'armchair',
  'desks-tables': 'table',
  'storage-furniture': 'boxes',
  'partitions-screens': 'columns-3',

  // IT Products
  computers: 'laptop',
  'monitors-displays': 'monitor',
  'computer-accessories': 'mouse',
  'storage-memory': 'hard-drive',
  networking: 'router',
  software: 'app-window',

  // Smart Tech
  'power-backup': 'battery-charging',
  'security-surveillance': 'cctv',
  'conferencing-av': 'video',
  'smart-office': 'house-plug',
  'cameras-imaging': 'camera',
  wearables: 'watch',

  // Printers & Consumables
  printers: 'printer',
  scanners: 'scan-line',
  'ink-toner': 'paint-bucket',
  copiers: 'copy',

  // Office Supplies
  writing: 'pen-line',
  'desk-accessories': 'lamp-desk',
  'adhesives-tapes': 'sticker',
  'boards-presentation': 'presentation',
  'office-machines': 'calculator',
  'labelling-identification': 'tag',

  // Paper & Notebooks
  'printing-paper': 'file-stack',
  'notebooks-pads': 'notebook-pen',
  'sticky-notes': 'sticky-note',
  'envelopes-mailing': 'mail',
  'specialty-paper': 'scroll',

  // Filing & Storage
  'files-folders': 'folder',
  'archive-boxes': 'box',
  'desk-filing': 'inbox',

  // School Supplies
  'art-craft': 'palette',
  'maths-geometry': 'ruler',
  'classroom-essentials': 'backpack',

  // WPC Doors & Interiors
  'wpc-doors': 'door-open',
  'wpc-wardrobes': 'shirt',
  'wpc-kitchens': 'cooking-pot',
  'wpc-interiors': 'panels-top-left',
  'wpc-boards': 'layers',
} as const satisfies Record<string, IconName>;

/** The glyph for a subcategory, or a neutral one if it has no entry. */
export function iconForCategory(slug: string): IconName {
  return (CATEGORY_ICONS as Record<string, IconName>)[slug] ?? 'chevron-right';
}


/**
 * An icon for every department.
 *
 * Added when the header bar was collapsed to a single non-wrapping row: with
 * fewer labels visible at once, a glyph does more work than it did when nine
 * of them were competing. The same map drives the panel's department rail.
 *
 * `printer` appears here and again on the Printers & Consumables panel's own
 * `printers` subcategory. That is not an oversight — a printer is what both
 * of them are, and reaching for a worse glyph to avoid the repetition would
 * make the menu harder to read, not easier.
 */
const DEPARTMENT_ICONS = {
  'office-furniture': 'sofa',
  'it-products': 'cpu',
  'smart-tech': 'wifi',
  'printers-consumables': 'printer',
  'office-supplies': 'paperclip',
  'paper-notebooks': 'notebook-text',
  'filing-storage': 'archive',
  'school-supplies': 'graduation-cap',
  'wpc-doors-interiors': 'door-closed',
} as const satisfies Record<string, IconName>;

/** The glyph for a department, or a neutral one if it has no entry. */
export function iconForDepartment(slug: string): IconName {
  return (DEPARTMENT_ICONS as Record<string, IconName>)[slug] ?? 'chevron-right';
}
