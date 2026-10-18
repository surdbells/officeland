import { TestBed } from '@angular/core/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { UrlSerializer, provideRouter } from '@angular/router';
import { TrailingSlashUrlSerializer } from '../core/routing/trailing-slash-url-serializer';
import { ProductCard } from './product-card';
import type { ProductCard as ProductCardData, Image } from '../core/api/catalogue-api';

/**
 * One card component serves the PLP, search, related rails and brand hubs, so
 * a regression here is a regression on every listing surface at once. These
 * assert the decisions that are easy to undo by accident.
 */
describe('ProductCard', () => {
  const image: Image = {
    uuid: 'aaaaaaaa-0000-0000-0000-000000000000',
    alt: 'HP LaserJet Pro M404dn',
    width: 800,
    height: 800,
    src: '/media/ab/cd/sha/pdp-800.jpeg',
    sources: [
      { type: 'image/avif', srcset: '/media/ab/cd/sha/card-400.avif 400w' },
      { type: 'image/jpeg', srcset: '/media/ab/cd/sha/card-400.jpeg 400w' },
    ],
  };

  const base: ProductCardData = {
    uuid: '11111111-0000-0000-0000-000000000000',
    name: 'M404dn HP LaserJet Pro Mono Printer',
    slug: 'm404dn-hp-laserjet-pro-mono-printer',
    url: '/p/m404dn-hp-laserjet-pro-mono-printer/',
    availability: 'in_stock',
    pricing_mode: 'quote_only',
  };

  /*
   * Configured once per test. Several cases render more than one card to
   * compare variants, and TestBed refuses to be reconfigured after the module
   * has been instantiated.
   *
   * The real UrlSerializer is provided because the card's hrefs are what the
   * crawler follows. With Angular's default the test would assert links that
   * production never emits, and D-028 exists precisely because that difference
   * costs a redirect on every link.
   */
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideZonelessChangeDetection(),
        provideRouter([]),
        { provide: UrlSerializer, useClass: TrailingSlashUrlSerializer },
        // The card now adds to the quote basket itself, so it injects
        // BasketStore, which needs HttpClient; the testing backend stubs it so
        // the add-to-quote click does not fire a real request.
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
  });

  function render(product: Partial<ProductCardData> = {}, headingLevel?: 2 | 3 | 4) {
    const fixture = TestBed.createComponent(ProductCard);
    fixture.componentRef.setInput('product', { ...base, ...product });
    if (headingLevel) fixture.componentRef.setInput('headingLevel', headingLevel);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  /**
   * D-002. A price appearing on a card is the single most damaging regression
   * possible here: it would be prerendered, cached for a year at the edge, and
   * wrong the moment a supplier moves.
   */
  it('shows no price anywhere', () => {
    const host = render();

    expect(host.textContent).not.toMatch(/₦|NGN|\$\d/);
  });

  it('renders the quote call to action, not an add-to-cart', () => {
    const host = render();
    const button = host.querySelector('button');

    expect(button?.textContent?.trim()).toBe('Add to quote');
  });

  it('emits the product when the quote button is pressed', () => {
    const fixture = TestBed.createComponent(ProductCard);
    fixture.componentRef.setInput('product', base);
    fixture.detectChanges();

    let emitted: ProductCardData | null = null;
    fixture.componentInstance.addToQuote.subscribe((p) => (emitted = p));

    (fixture.nativeElement as HTMLElement).querySelector('button')?.click();

    expect(emitted).toEqual(base);
  });

  describe('heading level', () => {
    it('defaults to h3', () => {
      expect(render().querySelector('h3')).toBeTruthy();
    });

    /**
     * A related rail sits under its own h2, so its cards must be h3; a PLP
     * card under a section h2 is also h3. The parameter exists so neither
     * surface has to fake it, and heading order is an accessibility gate.
     */
    it('honours an explicit level', () => {
      const host = render({}, 4);

      expect(host.querySelector('h4')).toBeTruthy();
      expect(host.querySelector('h3')).toBeFalsy();
    });
  });

  /**
   * The card must not be one giant anchor: it contains a brand link and a
   * button, and nesting those inside an anchor is invalid HTML and gives
   * screen readers one enormous unlabelled link.
   */
  it('links from the title rather than wrapping the whole card', () => {
    const host = render({ brand: { uuid: 'b', name: 'HP', slug: 'hp', url: '/brands/hp/' } });

    const titleLink = host.querySelector('h3 a');
    expect(titleLink?.getAttribute('href')).toBe('/p/m404dn-hp-laserjet-pro-mono-printer/');

    // The brand link survives as its own control.
    const links = [...host.querySelectorAll('a')].map((a) => a.getAttribute('href'));
    expect(links).toContain('/brands/hp/');

    expect(host.querySelector('a button')).toBeFalsy();
  });

  describe('identifier', () => {
    it('prefers model code, then MPN, then SKU', () => {
      expect(render({ model_code: 'MC', mpn: 'MPN', sku: 'SKU' }).textContent).toContain('MC');
      expect(render({ mpn: 'MPN', sku: 'SKU' }).textContent).toContain('MPN');
      expect(render({ sku: 'SKU' }).textContent).toContain('SKU');
    });
  });

  describe('availability', () => {
    it('names a stocked product', () => {
      expect(render({ availability: 'in_stock' }).textContent).toContain('In stock');
    });

    /** The B2B equivalent of a stock check: the number buyers ask for. */
    it('gives the lead time in days when there is one', () => {
      const host = render({ availability: 'lead_time', lead_time_days: 7 });

      expect(host.textContent).toContain('7 day lead time');
    });

    it('falls back when a lead-time product has no number yet', () => {
      const host = render({ availability: 'lead_time' });

      expect(host.textContent).toContain('Available on lead time');
    });
  });

  describe('order quantities', () => {
    /** "Minimum order 1" is noise on every card in the catalogue. */
    it('says nothing when the product has no constraint', () => {
      const host = render({ min_order_qty: 1, qty_step: 1 });

      expect(host.textContent).not.toContain('Minimum');
      expect(host.textContent).not.toContain('multiples');
    });

    it('states a minimum', () => {
      expect(render({ min_order_qty: 5, qty_step: 1 }).textContent).toContain('Minimum order 5');
    });

    it('states a step', () => {
      expect(render({ min_order_qty: 1, qty_step: 10 }).textContent).toContain(
        'Sold in multiples of 10',
      );
    });

    it('combines them only when they differ', () => {
      expect(render({ min_order_qty: 5, qty_step: 5 }).textContent).toContain('Minimum order 5');
      expect(render({ min_order_qty: 4, qty_step: 2 }).textContent).toContain(
        'Min 4, in multiples of 2',
      );
    });
  });

  describe('image', () => {
    it('renders a picture with one source per format', () => {
      const host = render({ image });

      expect(host.querySelectorAll('picture source').length).toBe(2);
      expect(host.querySelector('img')?.getAttribute('src')).toBe('/media/ab/cd/sha/pdp-800.jpeg');
    });

    /** Intrinsic dimensions are the CLS fix, and CLS is a Phase 8 gate. */
    it('sets intrinsic width and height', () => {
      const img = render({ image }).querySelector('img');

      expect(img?.getAttribute('width')).toBe('800');
      expect(img?.getAttribute('height')).toBe('800');
    });

    /** Grid cards are never the LCP element. */
    it('lazy-loads and does not claim high priority', () => {
      const img = render({ image }).querySelector('img');

      expect(img?.getAttribute('loading')).toBe('lazy');
      expect(img?.getAttribute('fetchpriority')).toBeNull();
    });

    it('holds the aspect ratio with a placeholder when there is no image', () => {
      const host = render();

      expect(host.querySelector('img')).toBeFalsy();
      expect(host.querySelector('[aria-hidden="true"]')).toBeTruthy();
    });
  });
});
