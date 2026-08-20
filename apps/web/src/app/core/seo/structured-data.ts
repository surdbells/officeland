/**
 * JSON-LD builders (MASTER BUILD PROMPT section 14.4).
 *
 * Pure functions over real data, no framework. They are unit-testable without
 * a browser, which matters because the structured-data CI gate validates their
 * output against Schema.org.
 *
 * The legacy site emits a BreadcrumbList and nothing else — no Product, no
 * Organization, no WebSite. Everything here is net new.
 */

const ORIGIN = 'https://officelandng.com';

export interface BreadcrumbEntry {
  readonly name: string;
  readonly url: string;
}

const absolute = (path: string): string =>
  path.startsWith('http') ? path : `${ORIGIN}${path.startsWith('/') ? path : `/${path}`}`;

/**
 * Site-wide Organization.
 *
 * `sameAs` is deliberately EMPTY until the real profile URLs arrive (Q17).
 * The legacy footer points every social link at `http://officelandng`, which
 * is not a URL; emitting those as sameAs would assert to Google that the
 * business is those broken links (legacy defect section 4G).
 */
export function organizationSchema(socialProfiles: readonly string[] = []): Record<string, unknown> {
  const node: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    '@id': `${ORIGIN}/#organization`,
    name: 'OfficeLand',
    legalName: 'OfficeLand Limited',
    url: ORIGIN,
    logo: `${ORIGIN}/assets/logo.svg`,
    contactPoint: [
      {
        '@type': 'ContactPoint',
        contactType: 'sales',
        email: 'sales@officelandng.com',
        telephone: '+2347074723165',
        areaServed: 'NG',
        availableLanguage: 'en',
      },
      {
        '@type': 'ContactPoint',
        contactType: 'customer support',
        email: 'support@officelandng.com',
        areaServed: 'NG',
        availableLanguage: 'en',
      },
    ],
  };

  // Only asserted when real, verified URLs exist.
  if (socialProfiles.length > 0) {
    node['sameAs'] = socialProfiles;
  }

  return node;
}

/** WebSite with a SearchAction, for the sitelinks searchbox (section 14.4). */
export function webSiteSchema(): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    '@id': `${ORIGIN}/#website`,
    url: ORIGIN,
    name: 'OfficeLand Nigeria',
    publisher: { '@id': `${ORIGIN}/#organization` },
    potentialAction: {
      '@type': 'SearchAction',
      target: {
        '@type': 'EntryPoint',
        urlTemplate: `${ORIGIN}/search?q={search_term_string}`,
      },
      'query-input': 'required name=search_term_string',
    },
  };
}

/**
 * BreadcrumbList — required on every product, category, brand and article page
 * (section 14.4), driven by primary_category_id.
 */
export function breadcrumbSchema(entries: readonly BreadcrumbEntry[]): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: entries.map((entry, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: entry.name,
      item: absolute(entry.url),
    })),
  };
}

export interface ProductSchemaInput {
  readonly name: string;
  readonly url: string;
  readonly description?: string | null;
  readonly sku?: string | null;
  readonly mpn?: string | null;
  readonly brand?: string | null;
  readonly images?: readonly string[];
  readonly availability?: string | null;
  /** 'public' | 'quote_only'. Under D-002 always 'quote_only'. */
  readonly pricingMode?: string | null;
  readonly specifications?: ReadonlyArray<{ label: string; value: string }>;
}

/**
 * Product.
 *
 * ── On `offers` ──────────────────────────────────────────────────────────
 * D-002 removes public pricing, and section 14.4 is explicit about what that
 * means: emit Product WITHOUT `offers` rather than faking a price. No
 * `price: 0`, no invented availability offer.
 *
 * The cost is real and accepted: no price rich results. Inventing a price to
 * get them would misrepresent an RFQ business to buyers and to Google, and a
 * price of zero is a lie that a merchant feed would eventually be penalised
 * for.
 *
 * `additionalProperty` carries the weight instead. Section 23.3 identified the
 * grouped specification table as the highest-leverage asset on the page, and
 * this is the machine-readable half of it.
 */
export function productSchema(input: ProductSchemaInput): Record<string, unknown> {
  const node: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: input.name,
    url: absolute(input.url),
  };

  if (input.description) node['description'] = input.description;
  if (input.sku) node['sku'] = input.sku;
  if (input.mpn) node['mpn'] = input.mpn;
  if (input.brand) node['brand'] = { '@type': 'Brand', name: input.brand };

  if (input.images?.length) {
    node['image'] = input.images.map(absolute);
  }

  if (input.specifications?.length) {
    node['additionalProperty'] = input.specifications.map((spec) => ({
      '@type': 'PropertyValue',
      name: spec.label,
      value: spec.value,
    }));
  }

  /*
   * AggregateRating is absent on purpose (section 14.6). The 4.4/138 Google
   * rating is a BUSINESS rating; presenting it as a product rating is a
   * policy violation and a manual-action risk. It may appear on
   * /locations/**, and product ratings wait for first-party reviews.
   */

  if (input.pricingMode === 'public') {
    // Unreachable under D-002 and left deliberately: enabling posture C should
    // be a data change, not a code change.
    node['offers'] = {
      '@type': 'Offer',
      priceCurrency: 'NGN',
      availability: schemaAvailability(input.availability),
      itemCondition: 'https://schema.org/NewCondition',
      seller: { '@id': `${ORIGIN}/#organization` },
    };
  }

  return node;
}

function schemaAvailability(availability?: string | null): string {
  switch (availability) {
    case 'in_stock':
      return 'https://schema.org/InStock';
    case 'limited':
      return 'https://schema.org/LimitedAvailability';
    case 'made_to_order':
      return 'https://schema.org/MadeToOrder';
    case 'discontinued':
      return 'https://schema.org/Discontinued';
    default:
      return 'https://schema.org/BackOrder';
  }
}

/** ItemList on category pages, in display order (section 14.4). */
export function itemListSchema(
  items: ReadonlyArray<{ name: string; url: string }>,
): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    numberOfItems: items.length,
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      url: absolute(item.url),
    })),
  };
}
