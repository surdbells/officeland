import type { BasketLine } from '../../core/api/catalogue-api';

/**
 * The basket as CSV (D-006).
 *
 * Exists because a procurement buyer's next step is usually a spreadsheet or a
 * purchase-order system, and retyping twenty lines is where a requisition gets
 * abandoned. There are no prices, so the columns are the identifiers a buyer
 * needs to raise the order once we have quoted it.
 */

const COLUMNS = ['Quantity', 'Item', 'Brand', 'SKU', 'MPN', 'Note', 'URL'] as const;

const ORIGIN = 'https://officelandng.com';

/**
 * Escapes one field for RFC 4180.
 *
 * ── The leading apostrophe is not a typo ─────────────────────────────────
 * A field starting with =, +, - or @ is executed as a formula when the file is
 * opened in Excel or Sheets. Our own data is clean, but a free-text line is
 * whatever the buyer typed, and this file is opened on someone's machine
 * outside our control. Prefixing with an apostrophe forces it to be read as
 * text, which is the standard mitigation for CSV injection.
 */
function escape(value: string | number | null | undefined): string {
  const text = value === null || value === undefined ? '' : String(value);

  const safe = /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;

  return `"${safe.replace(/"/g, '""')}"`;
}

export function toCsv(lines: readonly BasketLine[]): string {
  const rows = [COLUMNS.map(escape).join(',')];

  for (const line of lines) {
    rows.push(
      [
        escape(line.qty),
        escape(line.name),
        escape(line.brand),
        escape(line.sku),
        escape(line.mpn),
        escape(line.note),
        // Absolute, because the file is read away from the site and a
        // relative path in a spreadsheet points at nothing.
        escape(line.url ? `${ORIGIN}${line.url}` : ''),
      ].join(','),
    );
  }

  // CRLF per RFC 4180: Excel is the likeliest consumer and it is the one that
  // cares.
  return `${rows.join('\r\n')}\r\n`;
}
