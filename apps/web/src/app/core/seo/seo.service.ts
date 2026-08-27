import { DOCUMENT, Injectable, RendererFactory2, inject } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';

/**
 * Metadata for one page.
 *
 * `title` is the raw page name; the service applies the template and the
 * character budget. Callers never pre-format it, because then the budget would
 * be enforced in as many places as there are pages.
 */
export interface PageSeo {
  /**
   * The page name, or several candidate names in descending preference.
   *
   * With a list, the service picks the first that fits the budget whole rather
   * than truncating the first with an ellipsis. The category template
   * "Laser Printers in Nigeria - Prices & Bulk Supply" overflows by ten
   * characters, and blunt truncation produced
   * "Laser Printers in Nigeria - Prices… | OfficeLand Nigeria", which throws
   * away "Bulk Supply" - a phrase chosen for query intent - and ends a snippet
   * on a dangling word. Offering a shorter alternative keeps a whole phrase.
   */
  readonly title: string | readonly string[];
  readonly description?: string;
  /** Path only, e.g. '/p/hp-128a-toner/'. The service makes it absolute. */
  readonly path: string;
  readonly image?: string;
  readonly type?: 'website' | 'article' | 'product';
  readonly indexable?: boolean;
  readonly canonicalOverride?: string | null;
  /** Appended after the page name, before the site name. */
  readonly titleSuffix?: string;

  /**
   * An editor-written title, used verbatim.
   *
   * Distinct from `title` because the two are different things.
   * `title` offers candidates the service may decorate with the site name or
   * fall through; this is somebody's finished title tag, and appending to it
   * or discarding it in favour of a template makes the admin field a lie.
   *
   * It has to be separate rather than "the first candidate". Made a candidate,
   * a hand-written title is only used when it is short enough to also carry
   * " | OfficeLand Nigeria" - 21 characters of a 60-character budget - so the
   * template wins for anything longer than about 38 characters, which is most
   * titles worth writing. That is exactly what happened when meta_title was
   * first wired up: 46 categories had titles and not one of them appeared.
   *
   * Still subject to the hard budget: a 300-character title is a mistake, not
   * an intention.
   */
  readonly exactTitle?: string | null;
}

/**
 * The single owner of every metadata tag and every JSON-LD block
 * (MASTER BUILD PROMPT section 15.5).
 *
 * "No component sets meta tags directly." That is not tidiness: the legacy
 * homepage carries a 389-character title because nothing owned the budget.
 * One owner means one place enforces the 60/155 limits, one place decides
 * canonical shape, and one place can be tested.
 *
 * It must behave identically on the server and in the browser, because under
 * D-009 every catalogue page is rendered at build time - whatever this writes
 * during prerender is what a crawler receives.
 */
@Injectable({ providedIn: 'root' })
export class SeoService {
  private readonly title = inject(Title);
  private readonly meta = inject(Meta);
  private readonly document = inject(DOCUMENT);
  private readonly renderer = inject(RendererFactory2).createRenderer(null, null);

  /** Section 14.3 budgets. Over these, Google truncates mid-sentence. */
  static readonly TITLE_BUDGET = 60;
  static readonly DESCRIPTION_BUDGET = 155;

  private static readonly SITE_NAME = 'OfficeLand Nigeria';
  private static readonly ORIGIN = 'https://officelandng.com';

  /** Marks JSON-LD this service owns, so a re-render replaces rather than stacks. */
  private static readonly JSONLD_ATTR = 'data-seo-jsonld';

  apply(seo: PageSeo): void {
    const title = seo.exactTitle?.trim()
      ? this.truncate(seo.exactTitle.trim(), SeoService.TITLE_BUDGET)
      : this.composeTitle(seo.title, seo.titleSuffix);
    const description = this.truncate(seo.description ?? '', SeoService.DESCRIPTION_BUDGET);
    const canonical = seo.canonicalOverride ?? this.absolute(seo.path);

    this.title.setTitle(title);
    this.setCanonical(canonical);

    if (description) {
      this.meta.updateTag({ name: 'description', content: description });
    } else {
      this.meta.removeTag("name='description'");
    }

    /*
     * Indexability is explicit rather than implied. Section 14.7 defaults a
     * product to noindex until it carries enough original content, and search,
     * quote and filtered pages are noindex by design (SEO_SPEC.md section 2).
     * Emitting nothing would leave that to chance.
     */
    this.meta.updateTag({
      name: 'robots',
      content: seo.indexable === false ? 'noindex, follow' : 'index, follow',
    });

    // Open Graph. og:locale is en_NG - the audience is Nigerian, and the
    // default en_US misrepresents it to every social crawler.
    this.meta.updateTag({ property: 'og:title', content: title });
    this.meta.updateTag({ property: 'og:description', content: description });
    this.meta.updateTag({ property: 'og:url', content: canonical });
    this.meta.updateTag({ property: 'og:type', content: seo.type ?? 'website' });
    this.meta.updateTag({ property: 'og:site_name', content: SeoService.SITE_NAME });
    this.meta.updateTag({ property: 'og:locale', content: 'en_NG' });

    if (seo.image) {
      this.meta.updateTag({ property: 'og:image', content: this.absolute(seo.image) });
      this.meta.updateTag({ name: 'twitter:card', content: 'summary_large_image' });
    } else {
      this.meta.updateTag({ name: 'twitter:card', content: 'summary' });
    }

    /*
     * meta-keywords is never emitted (section 23.6 item 7). It is ignored by
     * every search engine and leaks internal taxonomy; the reference site
     * still ships one by accident.
     */
    this.meta.removeTag("name='keywords'");
  }

  /**
   * Replaces the PAGE-level JSON-LD: Product, BreadcrumbList, ItemList.
   *
   * Server-rendered into <head> from real data (section 14.4). One script per
   * node, so a malformed node cannot invalidate the rest.
   */
  setStructuredData(nodes: ReadonlyArray<Record<string, unknown>>): void {
    this.writeStructuredData(nodes, 'page');
  }

  /**
   * Replaces the SITE-level JSON-LD: Organization and WebSite.
   *
   * ── Why this is a separate scope ─────────────────────────────────────────
   * The shell sets Organization and WebSite once; each route then sets its own
   * nodes. With a single scope the route's call wiped the shell's, and every
   * product page shipped a Product and a BreadcrumbList with no Organization
   * to attach them to - section 14.4 requires all of them. The shell runs
   * before the route, so the loss was silent and total.
   *
   * Two scopes also mean a route change replaces only the page nodes; the
   * site-wide ones are written once and left alone.
   */
  setSiteStructuredData(nodes: ReadonlyArray<Record<string, unknown>>): void {
    this.writeStructuredData(nodes, 'site');
  }

  private writeStructuredData(
    nodes: ReadonlyArray<Record<string, unknown>>,
    scope: 'site' | 'page',
  ): void {
    this.clearStructuredData(scope);

    const head = this.document.head;

    for (const node of nodes) {
      const script = this.renderer.createElement('script');
      this.renderer.setAttribute(script, 'type', 'application/ld+json');
      this.renderer.setAttribute(script, SeoService.JSONLD_ATTR, scope);
      // Angular escapes text nodes, so a product name containing </script>
      // cannot break out of the block.
      this.renderer.appendChild(script, this.renderer.createText(JSON.stringify(node)));
      this.renderer.appendChild(head, script);
    }
  }

  private clearStructuredData(scope: 'site' | 'page'): void {
    const existing = this.document.head.querySelectorAll(
      `script[${SeoService.JSONLD_ATTR}="${scope}"]`,
    );
    existing.forEach((node) => node.remove());
  }

  /**
   * Applies the section 14.3 template within the 60-character budget.
   *
   * The order of sacrifice is specified: "drop the brand segment first, then
   * shorten the name". The site name goes last of all - a snippet that loses
   * it stops looking like a merchant.
   */
  private composeTitle(name: string | readonly string[], suffix?: string): string {
    const site = SeoService.SITE_NAME;
    const budget = SeoService.TITLE_BUDGET;

    const candidates = (typeof name === 'string' ? [name] : name).filter(
      (candidate) => candidate.trim() !== '',
    );
    const fallback = candidates[candidates.length - 1] ?? '';

    /*
     * A whole shorter phrase beats a truncated longer one, so every candidate
     * is tried at full fidelity before anything is sacrificed. Only once none
     * of them fits does the section 14.3 sacrifice order begin.
     */
    for (const candidate of candidates) {
      if (suffix) {
        const withAll = `${candidate} | ${suffix} | ${site}`;
        if (withAll.length <= budget) return withAll;
      }

      const withoutSuffix = `${candidate} | ${site}`;
      if (withoutSuffix.length <= budget) return withoutSuffix;
    }

    /*
     * Before shortening anything, try dropping the site name.
     *
     * Product titles use the agreed [code] [brand] [name] format, and those
     * run 40-55 characters routinely: "CE320A HP 128A Black LaserJet Toner
     * Cartridge" is 45. Add " | OfficeLand Nigeria" and every one of them
     * overflows, so the budget would truncate the model name on essentially
     * the whole catalogue - and the model name is the part that matches the
     * search. "…LaserJet Toner… | OfficeLand Nigeria" loses the query term to
     * keep branding that Google frequently appends by itself.
     *
     * So the site name is sacrificed only when the alternative is mangling
     * the name, and never merely to make room for the suffix.
     */
    for (const candidate of candidates) {
      const trimmed = candidate.trim();
      if (trimmed.length <= budget) return trimmed;
    }

    /*
     * Last resort: shorten the shortest candidate, keeping the site name. It
     * is the shortest that gets truncated because it loses the least.
     *
     * Only reached when even the bare name exceeds the whole budget, which
     * means the name is the problem rather than the template.
     */
    return `${this.truncate(fallback, budget - site.length - 3)} | ${site}`;
  }

  /** Truncates on a word boundary so a snippet never ends mid-word. */
  private truncate(value: string, limit: number): string {
    const clean = value.trim().replace(/\s+/g, ' ');
    if (clean.length <= limit) return clean;

    const cut = clean.slice(0, limit - 1);
    const lastSpace = cut.lastIndexOf(' ');

    return `${(lastSpace > limit * 0.6 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
  }

  /**
   * Self-referencing absolute canonical (section 14.3).
   *
   * Absolute because a relative canonical is resolved against the current URL,
   * which on a filtered or parameterised page is the wrong document.
   */
  private setCanonical(href: string): void {
    let link = this.document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');

    if (!link) {
      link = this.renderer.createElement('link');
      this.renderer.setAttribute(link, 'rel', 'canonical');
      this.renderer.appendChild(this.document.head, link!);
    }

    this.renderer.setAttribute(link, 'href', href);
  }

  private absolute(path: string): string {
    if (path.startsWith('http')) return path;

    // One hostname, one protocol, trailing slash preserved (SEO_SPEC.md 2).
    return `${SeoService.ORIGIN}${path.startsWith('/') ? path : `/${path}`}`;
  }
}
