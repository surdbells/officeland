import { TrailingSlashUrlSerializer } from './trailing-slash-url-serializer';

/**
 * D-028. Angular's default serializer drops trailing slashes, so every
 * internal link would point at a URL Caddy answers with a redirect — one
 * avoidable hop per link, across 15,000 pages, in direct conflict with the
 * canonicals SeoService emits.
 *
 * These are unit tests on the serializer rather than router integration tests
 * because the edge cases (files, queries, fragments) are cheaper to state
 * directly than to construct routes for.
 */
describe('TrailingSlashUrlSerializer', () => {
  const serializer = new TrailingSlashUrlSerializer();
  const round = (url: string) => serializer.serialize(serializer.parse(url));

  it('adds the slash to a path that lacks one', () => {
    expect(round('/c/office-furniture')).toBe('/c/office-furniture/');
  });

  it('leaves an already-slashed path alone', () => {
    expect(round('/c/office-furniture/')).toBe('/c/office-furniture/');
  });

  it('leaves the root alone rather than doubling it', () => {
    expect(round('/')).toBe('/');
  });

  it('handles a deep category path', () => {
    expect(round('/c/office-furniture/seating/executive-chairs')).toBe(
      '/c/office-furniture/seating/executive-chairs/',
    );
  });

  /**
   * The slash belongs to the path, so it goes before the query — not after.
   * Appending it to the end would produce '/search?q=toner/', which is a
   * different query, not a different path.
   */
  it('puts the slash before a query string', () => {
    expect(round('/search?q=toner')).toBe('/search/?q=toner');
  });

  it('puts the slash before a fragment', () => {
    expect(round('/delivery#lead-times')).toBe('/delivery/#lead-times');
  });

  it('handles a query and a fragment together', () => {
    expect(round('/search?q=toner#results')).toBe('/search/?q=toner#results');
  });

  /**
   * A dot in the last segment means a file. Giving /favicon.ico a directory
   * slash would 404 it.
   */
  it('leaves a file path alone', () => {
    expect(round('/favicon.ico')).toBe('/favicon.ico');
    expect(round('/sitemap.xml')).toBe('/sitemap.xml');
  });

  it('still slashes a path whose earlier segment contains a dot', () => {
    expect(round('/brands/3m/tapes')).toBe('/brands/3m/tapes/');
  });

  it('preserves multiple query parameters', () => {
    const result = round('/c/it-products?brand=hp&capacity=480');

    expect(result.startsWith('/c/it-products/?')).toBe(true);
    expect(result).toContain('brand=hp');
    expect(result).toContain('capacity=480');
  });
});
