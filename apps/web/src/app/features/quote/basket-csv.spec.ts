import { toCsv } from './basket-csv';
import type { BasketLine } from '../../core/api/catalogue-api';

/**
 * The CSV export (D-006).
 *
 * A buyer downloads this and opens it on their own machine, so the escaping is
 * not cosmetic: a free-text line is whatever they typed, and a spreadsheet
 * will execute it if we let it.
 */
describe('basket CSV', () => {
  const line = (overrides: Partial<BasketLine> = {}): BasketLine => ({
    id: '1',
    qty: 2,
    name: 'M404dn HP LaserJet Pro Mono Printer',
    is_free_text: false,
    ...overrides,
  });

  it('writes a header row', () => {
    expect(toCsv([])).toBe('"Quantity","Item","Brand","SKU","MPN","Note","URL"\r\n');
  });

  it('writes one row per line', () => {
    const rows = toCsv([line(), line({ id: '2', name: 'Chair' })]).trim().split('\r\n');

    expect(rows).toHaveLength(3);
    expect(rows[1]).toContain('M404dn HP LaserJet Pro Mono Printer');
    expect(rows[2]).toContain('Chair');
  });

  /** RFC 4180: a quote inside a quoted field is doubled. */
  it('escapes embedded quotes', () => {
    const csv = toCsv([line({ name: 'A4 paper 80gsm "premium"' })]);

    expect(csv).toContain('"A4 paper 80gsm ""premium"""');
  });

  it('keeps commas and newlines inside the field', () => {
    const csv = toCsv([line({ note: 'Black, grey\nor white' })]);

    expect(csv).toContain('"Black, grey\nor white"');
  });

  /**
   * CSV injection. A field starting with =, +, - or @ is executed as a
   * formula by Excel and Sheets. Free text is user input and this file is
   * opened outside our control, so it is forced to text with a leading
   * apostrophe - the standard mitigation.
   */
  describe('formula injection', () => {
    for (const dangerous of ['=1+1', '+1', '-1', '@SUM(A1)', '=HYPERLINK("http://evil")']) {
      it(`neutralises a field starting with ${dangerous[0]}`, () => {
        const csv = toCsv([line({ name: dangerous })]);

        // The apostrophe goes inside the quoting, and any quotes in the value
        // are doubled per RFC 4180 - so the field opens with "' and then the
        // escaped value, not the raw one.
        expect(csv).toContain(`"'${dangerous.replace(/"/g, '""')}"`);
      });
    }

    it('leaves ordinary text alone', () => {
      const csv = toCsv([line({ name: 'Paper A4' })]);

      expect(csv).toContain('"Paper A4"');
      expect(csv).not.toContain("\"'Paper A4\"");
    });
  });

  /** A relative path in a spreadsheet points at nothing. */
  it('makes product URLs absolute', () => {
    const csv = toCsv([line({ url: '/p/thing/' })]);

    expect(csv).toContain('https://officelandng.com/p/thing/');
  });

  it('leaves the URL column empty for a free-text line', () => {
    const csv = toCsv([line({ is_free_text: true, url: undefined })]);

    expect(csv.trim().split('\r\n')[1]?.endsWith(',""')).toBe(true);
  });

  it('writes empty strings rather than the word undefined', () => {
    const csv = toCsv([line({ brand: undefined, sku: undefined, note: undefined })]);

    expect(csv).not.toContain('undefined');
    expect(csv).not.toContain('null');
  });
});
