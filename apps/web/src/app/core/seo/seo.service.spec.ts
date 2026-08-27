import { TestBed } from '@angular/core/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { SeoService } from './seo.service';

/**
 * The metadata budget is the one piece of this codebase that fails silently.
 * A title over budget still renders; it is only wrong in a SERP nobody on the
 * team is looking at. The legacy site shipped a 389-character title for years.
 */
describe('SeoService', () => {
  let seo: SeoService;

  const head = () => document.head;
  const title = () => document.title;
  const canonical = () => head().querySelector<HTMLLinkElement>('link[rel="canonical"]')?.href;
  const meta = (selector: string) =>
    head().querySelector<HTMLMetaElement>(`meta[${selector}]`)?.content;
  const jsonLd = () => Array.from(head().querySelectorAll('script[type="application/ld+json"]'));

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideZonelessChangeDetection()] });
    seo = TestBed.inject(SeoService);

    head()
      .querySelectorAll('link[rel="canonical"], script[type="application/ld+json"]')
      .forEach((node) => node.remove());
  });

  describe('title budget', () => {
    it('keeps a short title whole, with the suffix and the site name', () => {
      seo.apply({ title: 'HP 128A Toner', titleSuffix: 'HP', path: '/p/x/' });

      expect(title()).toBe('HP 128A Toner | HP | OfficeLand Nigeria');
      expect(title().length).toBeLessThanOrEqual(SeoService.TITLE_BUDGET);
    });

    it('sacrifices the suffix before the name', () => {
      // 38 characters: over budget with the suffix, inside it without.
      seo.apply({
        title: 'HP 128A Black LaserJet Toner Cartridge',
        titleSuffix: 'HP',
        path: '/p/x/',
      });

      expect(title()).toBe('HP 128A Black LaserJet Toner Cartridge | OfficeLand Nigeria');
    });

    /*
     * A real product title in the agreed [code] [brand] [name] format. At 45
     * characters it cannot carry the site name, and the model code is the
     * part a buyer actually searches for, so it is kept whole.
     */
    it('keeps a long product name whole rather than truncating it for the site name', () => {
      seo.apply({
        title: 'CE320A HP 128A Black LaserJet Toner Cartridge',
        titleSuffix: 'HP',
        path: '/p/x/',
      });

      expect(title()).toBe('CE320A HP 128A Black LaserJet Toner Cartridge');
      expect(title()).not.toContain('…');
    });

    it('keeps the site name when the name has to be shortened', () => {
      seo.apply({ title: 'A'.repeat(120), path: '/p/x/' });

      expect(title().endsWith('| OfficeLand Nigeria')).toBe(true);
      expect(title().length).toBeLessThanOrEqual(SeoService.TITLE_BUDGET);
    });

    /**
     * The case that motivated candidate titles: the full category template
     * overflows, and truncating it drops "Bulk Supply" and ends on an ellipsis.
     */
    it('prefers a whole shorter candidate over a truncated longer one', () => {
      seo.apply({
        title: [
          'Ergonomic & Task Chairs in Nigeria - Prices & Bulk Supply',
          'Ergonomic & Task Chairs - Prices & Bulk Supply',
          'Ergonomic & Task Chairs in Nigeria',
          'Ergonomic & Task Chairs',
        ],
        path: '/c/office-furniture/seating/ergonomic-task-chairs/',
      });

      expect(title()).not.toContain('…');
      expect(title().length).toBeLessThanOrEqual(SeoService.TITLE_BUDGET);
    });

    it('takes the first candidate that fits, not merely the shortest', () => {
      seo.apply({ title: ['Laser Printers in Nigeria', 'Laser Printers'], path: '/c/x/' });

      expect(title()).toBe('Laser Printers in Nigeria | OfficeLand Nigeria');
    });

    it('breaks a truncated title on a word boundary', () => {
      seo.apply({ title: 'Executive leather office chairs for boardrooms and directors offices'.repeat(3), path: '/c/x/' });

      // The ellipsis follows a space-delimited word, never a part-word.
      expect(title()).toMatch(/\s?…/);
    });

    /*
     * The deliberate exception. A single token longer than the budget has no
     * word boundary to break on, so it is cut hard. Asserted so the fallback
     * is a decision rather than a surprise.
     */
    it('hard-cuts a single token that has no word boundary', () => {
      seo.apply({ title: 'A'.repeat(200), path: '/p/x/' });

      expect(title().length).toBeLessThanOrEqual(SeoService.TITLE_BUDGET);
      expect(title()).toMatch(/^A+… \| OfficeLand Nigeria$/);
    });
  });

  describe('description budget', () => {
    it('truncates to the section 14.3 limit', () => {
      seo.apply({ title: 'X', description: 'word '.repeat(80), path: '/p/x/' });

      expect(meta("name='description'")!.length).toBeLessThanOrEqual(
        SeoService.DESCRIPTION_BUDGET,
      );
    });

    it('emits no description tag rather than an empty one', () => {
      seo.apply({ title: 'X', path: '/p/x/' });

      expect(meta("name='description'")).toBeUndefined();
    });
  });

  describe('canonical', () => {
    it('is absolute, because a relative one resolves against the wrong document', () => {
      seo.apply({ title: 'X', path: '/p/hp-128a/' });

      expect(canonical()).toBe('https://officelandng.com/p/hp-128a/');
    });

    it('preserves the trailing slash', () => {
      seo.apply({ title: 'X', path: '/c/office-furniture/' });

      expect(canonical()!.endsWith('/')).toBe(true);
    });

    it('honours an override', () => {
      seo.apply({ title: 'X', path: '/p/a/', canonicalOverride: 'https://officelandng.com/p/b/' });

      expect(canonical()).toBe('https://officelandng.com/p/b/');
    });

    it('replaces rather than appends across navigations', () => {
      seo.apply({ title: 'X', path: '/p/a/' });
      seo.apply({ title: 'Y', path: '/p/b/' });

      expect(head().querySelectorAll('link[rel="canonical"]').length).toBe(1);
    });
  });

  describe('robots', () => {
    it('defaults to indexable', () => {
      seo.apply({ title: 'X', path: '/p/x/' });

      expect(meta("name='robots'")).toBe('index, follow');
    });

    /** Section 14.7: below the content floor a product must not be indexed. */
    it('emits noindex, follow when told the page is not indexable', () => {
      seo.apply({ title: 'X', path: '/search', indexable: false });

      expect(meta("name='robots'")).toBe('noindex, follow');
    });
  });

  /** Section 23.6 item 7. Ignored by search engines, leaks internal taxonomy. */
  it('never emits meta keywords', () => {
    document.head.appendChild(
      Object.assign(document.createElement('meta'), { name: 'keywords', content: 'leaked' }),
    );

    seo.apply({ title: 'X', path: '/p/x/' });

    expect(meta("name='keywords'")).toBeUndefined();
  });

  describe('structured data', () => {
    it('writes one script per node', () => {
      seo.setStructuredData([{ '@type': 'Product' }, { '@type': 'BreadcrumbList' }]);

      expect(jsonLd().length).toBe(2);
    });

    it('emits valid JSON', () => {
      seo.setStructuredData([{ '@type': 'Product', name: 'A "quoted" name' }]);

      expect(() => JSON.parse(jsonLd()[0]!.textContent!)).not.toThrow();
    });

    it('replaces page nodes on the next page rather than stacking them', () => {
      seo.setStructuredData([{ '@type': 'Product' }]);
      seo.setStructuredData([{ '@type': 'Product' }]);

      expect(jsonLd().length).toBe(1);
    });

    /**
     * The regression that shipped every product page without an Organization:
     * the shell writes site nodes first, then the route writes page nodes.
     */
    it('keeps site-level nodes when a route sets its own', () => {
      seo.setSiteStructuredData([{ '@type': 'Organization' }, { '@type': 'WebSite' }]);
      seo.setStructuredData([{ '@type': 'Product' }, { '@type': 'BreadcrumbList' }]);

      const types = jsonLd().map((node) => JSON.parse(node.textContent!)['@type']);

      expect(types).toContain('Organization');
      expect(types).toContain('WebSite');
      expect(types).toContain('Product');
      expect(types).toContain('BreadcrumbList');
    });

    it('does not let a route wipe site nodes on repeated navigation', () => {
      seo.setSiteStructuredData([{ '@type': 'Organization' }]);
      seo.setStructuredData([{ '@type': 'Product' }]);
      seo.setStructuredData([{ '@type': 'ItemList' }]);

      const types = jsonLd().map((node) => JSON.parse(node.textContent!)['@type']);

      expect(types).toEqual(['Organization', 'ItemList']);
    });
  });
});
