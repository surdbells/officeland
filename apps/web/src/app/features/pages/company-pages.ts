import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of } from 'rxjs';
import { ContentPage } from './content-page';
import { Icon, type IconName } from '../../shared/icon';
import { ResponsiveImage } from '../../shared/responsive-image';
import { Reveal } from '../../shared/reveal';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { localBusinessSchema } from '../../core/seo/structured-data';

/**
 * About, Contact and Showrooms.
 *
 * Three components in one file because they are one lazy-loaded chunk. These
 * are indexable: they are the pages a search for "office furniture showroom
 * Victoria Island" should land on, and they carry the entity information a
 * local search result is assembled from.
 *
 * Every figure on these pages is a fact from docs/BUSINESS_FACTS.md - nine
 * departments, two Lagos showrooms, quotes inside one business day, SON
 * certification. Nothing here is invented for the layout: no founding year, no
 * customer count, no opening hours, because none of those are confirmed.
 */

const WHATSAPP = 'https://wa.me/2347074723165';

const LOCATIONS = [
  {
    name: 'Head Office',
    kind: 'Head office',
    photo: '/images/locations/head-office.webp',
    alt: 'The OfficeLand counter inside Century 21 Mall, with HP authorised reseller signage and shelves of stationery and IT supplies',
    lines: ['Century 21 Mall, 14B Idowu Martins Street', 'Victoria Island, Lagos'],
    what: 'Sales, quotations and the stationery, IT and consumables counter.',
    directions:
      'https://www.google.com/maps/search/?api=1&query=Century+21+Mall+14B+Idowu+Martins+Street+Victoria+Island+Lagos',
  },
  {
    name: 'Furniture Showroom',
    kind: 'Showroom',
    photo: '/images/locations/furniture-showroom.webp',
    alt: 'The OfficeLand furniture showroom on Idowu Martins Street, its frontage listing desks, chairs, conference tables and filing cabinets',
    lines: ['No 18, Idowu Martins Street', 'Victoria Island, Lagos State'],
    what: 'Chairs, desks, workstations, conference tables and storage on display.',
    directions: 'https://www.google.com/maps/search/?api=1&query=18+Idowu+Martins+Street+Victoria+Island+Lagos',
  },
] as const;

@Component({
  selector: 'ol-about-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink, Icon, ResponsiveImage, Reveal],
  template: `
    <ol-content-page
      heading="About OfficeLand"
      crumb="About"
      eyebrow="Who we are"
      image="/images/pages/about.webp"
      lede="We supply and install office furniture, IT equipment and consumables for businesses, schools and government across Nigeria - from two showrooms on Victoria Island, Lagos."
      [wide]="true"
    >
      <!-- The facts, as a strip. -->
      <ul class="-mt-20 grid grid-cols-2 gap-3 sm:-mt-24 sm:gap-4 lg:grid-cols-4">
        @for (fact of facts; track fact.label) {
          <li olReveal class="rounded-2xl border border-ink-100 bg-white p-5 shadow-md sm:p-6">
            <span class="inline-flex rounded-xl bg-brand-50 p-2.5 text-brand-600">
              <ol-icon [name]="fact.icon" [size]="20" />
            </span>
            <p class="mt-3 font-heading text-2xl font-bold text-ink-900 sm:text-3xl">{{ fact.value }}</p>
            <p class="mt-0.5 text-sm text-ink-600">{{ fact.label }}</p>
          </li>
        }
      </ul>

      <!-- Who we are, with the principles beside it. -->
      <div class="mt-14 grid gap-10 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:gap-14">
        <div class="ol-prose max-w-3xl text-ink-600">
          <h2 class="!mt-0 font-heading !text-2xl font-bold !leading-tight text-ink-900 sm:!text-3xl">Built for people buying on behalf of an organisation</h2>
          <p>
            OfficeLand Limited works from Victoria Island, Lagos, with a head office at
            Century 21 Mall and a furniture showroom a few doors away on Idowu Martins
            Street. Most of what we sell is bought by somebody buying on behalf of an
            organisation rather than for themselves - a facilities manager, a school
            bursar, a procurement officer working to a schedule and a deadline.
          </p>
          <p>
            That shapes how the business works. We quote rather than publish prices,
            because the price of forty chairs delivered and installed on the third floor
            is not the price of one chair collected from a shop. Quotes are answered within
            one business day, with lead times stated per line rather than averaged across
            the order.
          </p>
          <p>
            We list a fraction of what we can supply. If something is not on the site,
            <a routerLink="/quote/">ask</a>: the catalogue reflects what is worth
            maintaining a page for, not the limit of what we source.
          </p>
        </div>

        <ul class="space-y-4">
          @for (principle of principles; track principle.title) {
            <li olReveal class="flex gap-4 rounded-2xl border border-ink-100 bg-white p-5 shadow-xs">
              <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
                <ol-icon [name]="principle.icon" [size]="20" />
              </span>
              <span>
                <span class="block font-heading text-base font-semibold text-ink-900">{{ principle.title }}</span>
                <span class="mt-1 block text-sm leading-relaxed text-ink-600">{{ principle.text }}</span>
              </span>
            </li>
          }
        </ul>
      </div>

      <!-- What we supply: the nine departments, as image tiles. -->
      @if (departments().length) {
        <section aria-labelledby="about-departments" class="mt-16">
          <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="ol-eyebrow">What we supply</p>
              <h2 id="about-departments" class="mt-1 font-heading text-2xl font-bold sm:text-3xl">Nine departments, one supplier</h2>
            </div>
            <a routerLink="/c/" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
              All departments
              <ol-icon name="arrow-right" [size]="16" />
            </a>
          </div>
          <ul class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
            @for (department of departments(); track department.slug) {
              <li>
                <a
                  [routerLink]="department.url"
                  class="ol-lift ol-zoom-frame group relative isolate flex aspect-[4/5] flex-col justify-end overflow-hidden rounded-2xl bg-brand-900 p-4 text-white"
                >
                  @if (department.card ?? department.hero; as image) {
                    <span class="absolute inset-0 -z-20">
                      <ol-image
                        class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                        [image]="image"
                        sizes="(min-width: 1024px) 18vw, (min-width: 640px) 30vw, 45vw"
                        imgClass="h-full w-full object-cover"
                      />
                    </span>
                  }
                  <span aria-hidden="true" class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-900/85 via-ink-900/25 to-transparent"></span>
                  <span class="font-heading text-base font-semibold leading-tight text-white">{{ department.name }}</span>
                  <span class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-white/80 group-hover:text-accent-400">
                    Shop
                    <ol-icon name="arrow-right" [size]="13" />
                  </span>
                </a>
              </li>
            }
          </ul>
        </section>
      }

      <!-- Standards and installation, as a split band. -->
      <section class="mt-16 grid gap-4 lg:grid-cols-2">
        <div olReveal class="rounded-3xl border border-ink-100 bg-white p-7 shadow-xs sm:p-9">
          <span class="inline-flex rounded-xl bg-brand-50 p-3 text-brand-600"><ol-icon name="badge-check" [size]="22" /></span>
          <h2 class="mt-4 font-heading text-xl font-semibold text-ink-900">Standards and accreditation</h2>
          <p class="mt-2 leading-relaxed text-ink-600">
            We are certified by the Standards Organisation of Nigeria. For institutional and
            government buyers this is frequently a qualification requirement rather than a
            badge, and we supply the documentation with a quotation when a tender asks for it.
          </p>
          <a routerLink="/bulk-supply/" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
            Bulk &amp; tender supply <ol-icon name="arrow-right" [size]="14" />
          </a>
        </div>
        <div olReveal class="rounded-3xl bg-brand-600 p-7 text-white shadow-brand sm:p-9">
          <span class="inline-flex rounded-xl bg-white/15 p-3 text-white"><ol-icon name="truck" [size]="22" /></span>
          <h2 class="mt-4 font-heading text-xl font-semibold text-white">Delivered and installed by our own people</h2>
          <p class="mt-2 leading-relaxed text-white/85">
            Furniture and fit-out work is installed by our own team in Lagos and quoted per
            site elsewhere. A chair assembled badly is a warranty claim, and a wardrobe fitted
            to an out-of-level floor binds within months - so we would rather install what we
            supply.
          </p>
          <a routerLink="/delivery/" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-white underline decoration-accent-400 underline-offset-4">
            Delivery &amp; lead times <ol-icon name="arrow-right" [size]="14" />
          </a>
        </div>
      </section>
    </ol-content-page>
  `,
})
export class AboutPage {
  private readonly seo = inject(SeoService);
  private readonly api = inject(CatalogueApi);

  protected readonly departments = toSignal(
    this.api.navigation().pipe(
      map((navigation) => navigation.departments),
      catchError(() => of([])),
    ),
    { initialValue: [] },
  );

  protected readonly facts: readonly { icon: IconName; value: string; label: string }[] = [
    { icon: 'layers', value: '9', label: 'Product departments' },
    { icon: 'store', value: '2', label: 'Showrooms on Victoria Island' },
    { icon: 'clock', value: '1 day', label: 'To answer a quote' },
    { icon: 'badge-check', value: 'SON', label: 'Certified supplier' },
  ];

  protected readonly principles: readonly { icon: IconName; title: string; text: string }[] = [
    { icon: 'file-text', title: 'Quoted, not guessed', text: 'Every line priced for your quantity, site and deadline - with lead times stated per item.' },
    { icon: 'scan-line', title: 'Specification first', text: 'We say when we would substitute an item, and why. A quote that silently swaps a spec fails at evaluation.' },
    { icon: 'shield-check', title: 'After the sale too', text: 'Manufacturer warranties, claims handled by us. You deal with one supplier from quotation to installation.' },
  ];

  constructor() {
    this.seo.apply({
      title: ['About OfficeLand - Office Supply Partner in Lagos', 'About OfficeLand'],
      description:
        'OfficeLand Limited supplies and installs office furniture, IT and consumables for business, schools and government across Nigeria. Two Victoria Island showrooms.',
      path: '/about/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-contact-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink, Icon, Reveal],
  template: `
    <ol-content-page
      heading="Talk to us"
      crumb="Contact"
      eyebrow="Contact"
      image="/images/pages/contact.webp"
      lede="Quotes answered within one business day. For anything urgent, WhatsApp is the fastest route to a person."
      [wide]="true"
    >
      <!-- The channels. -->
      <ul class="-mt-20 grid gap-3 sm:-mt-24 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
        @for (channel of channels; track channel.label) {
          <li olReveal>
            <a
              [href]="channel.href"
              [attr.rel]="channel.external ? 'noopener' : null"
              class="ol-lift group flex h-full flex-col rounded-2xl border border-ink-100 bg-white p-6 shadow-md hover:border-brand-600"
            >
              <span [class]="channel.primary ? 'inline-flex w-fit rounded-xl bg-emerald-500 p-3 text-white' : 'inline-flex w-fit rounded-xl bg-brand-50 p-3 text-brand-600'">
                <ol-icon [name]="channel.icon" [size]="22" />
              </span>
              <span class="mt-4 text-xs font-semibold uppercase tracking-[0.12em] text-ink-400">{{ channel.label }}</span>
              <span class="ol-wrap-anywhere mt-1 font-heading text-lg font-semibold text-ink-900 group-hover:text-brand-700">{{ channel.value }}</span>
              <span class="mt-1 text-sm text-ink-600">{{ channel.note }}</span>
            </a>
          </li>
        }
      </ul>

      <!-- The quickest route to a price, then what to include. -->
      <div class="mt-14 grid gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <section olReveal class="relative isolate overflow-hidden rounded-3xl bg-brand-600 p-8 text-white shadow-brand sm:p-10">
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">Get a price</p>
          <h2 class="mt-2 font-heading text-2xl font-bold text-white sm:text-3xl">Send the list - you do not need to find the products first</h2>
          <p class="mt-3 max-w-xl text-white/85">
            A requisition schedule, a drawing, a photograph of what you need to match, or a
            description. We price every line and come back within one business day.
          </p>
          <div class="mt-6 flex flex-wrap gap-3">
            <a routerLink="/quote/" class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 font-semibold text-ink-900 transition-colors hover:bg-accent-400">
              Request a quote <ol-icon name="arrow-right" [size]="16" />
            </a>
            <a routerLink="/bulk-supply/" class="inline-flex items-center gap-2 rounded-full border border-white/60 px-6 py-3 font-semibold text-white transition-colors hover:bg-white/10">
              Tenders &amp; bulk orders
            </a>
          </div>
        </section>

        <section olReveal class="rounded-3xl border border-ink-100 bg-white p-8 shadow-xs sm:p-10">
          <h2 class="font-heading text-xl font-semibold text-ink-900">What to include</h2>
          <p class="mt-2 text-sm text-ink-600">Three things get you a faster, firmer answer on any channel:</p>
          <ol class="mt-5 space-y-4">
            @for (item of include; track item.title; let i = $index) {
              <li class="flex gap-4">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-900 text-sm font-bold text-white">{{ i + 1 }}</span>
                <span>
                  <span class="block font-semibold text-ink-900">{{ item.title }}</span>
                  <span class="block text-sm text-ink-600">{{ item.text }}</span>
                </span>
              </li>
            }
          </ol>
        </section>
      </div>

      <!-- Where to find us. -->
      <section aria-labelledby="contact-visit" class="mt-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <p class="ol-eyebrow">Come in</p>
            <h2 id="contact-visit" class="mt-1 font-heading text-2xl font-bold sm:text-3xl">Two addresses on Idowu Martins Street</h2>
          </div>
          <a routerLink="/locations/" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
            Showrooms &amp; directions <ol-icon name="arrow-right" [size]="16" />
          </a>
        </div>
        <ul class="mt-6 grid gap-4 md:grid-cols-2">
          @for (location of locations; track location.name) {
            <li olReveal class="flex gap-4 rounded-2xl border border-ink-100 bg-white p-5 shadow-xs">
              <img [src]="location.photo" [alt]="location.alt" width="160" height="120" loading="lazy" decoding="async" class="h-24 w-32 shrink-0 rounded-xl object-cover" />
              <div class="min-w-0">
                <p class="font-heading font-semibold text-ink-900">{{ location.name }}</p>
                <address class="mt-1 text-sm not-italic text-ink-600">{{ location.lines[0] }}, {{ location.lines[1] }}</address>
                <a [href]="location.directions" rel="noopener" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
                  <ol-icon name="map-pin" [size]="15" /> Get directions
                </a>
              </div>
            </li>
          }
        </ul>
      </section>
    </ol-content-page>
  `,
})
export class ContactPage {
  private readonly seo = inject(SeoService);

  protected readonly locations = LOCATIONS;

  protected readonly channels: readonly {
    icon: IconName;
    label: string;
    value: string;
    note: string;
    href: string;
    external: boolean;
    primary: boolean;
  }[] = [
    { icon: 'message-circle', label: 'WhatsApp', value: '+234 707 472 3165', note: 'Fastest route to a person.', href: WHATSAPP, external: true, primary: true },
    { icon: 'phone', label: 'Call', value: '+234 707 472 3165', note: 'Sales and quotations.', href: 'tel:+2347074723165', external: false, primary: false },
    { icon: 'mail', label: 'Sales', value: 'sales@officelandng.com', note: 'Quotes, orders, tenders.', href: 'mailto:sales@officelandng.com', external: false, primary: false },
    { icon: 'mail', label: 'Support', value: 'support@officelandng.com', note: 'Deliveries, warranty, after the sale.', href: 'mailto:support@officelandng.com', external: false, primary: false },
  ];

  protected readonly include = [
    { title: 'The quantity', text: 'Firm or indicative - it changes the price.' },
    { title: 'The delivery address', text: 'And any access constraints on site.' },
    { title: 'The date you need it by', text: 'Lead time most often changes a recommendation, and is most often left out.' },
  ] as const;

  constructor() {
    this.seo.apply({
      title: ['Contact OfficeLand - Lagos Office Supplies', 'Contact OfficeLand', 'Contact'],
      description:
        'Call, WhatsApp or email OfficeLand Lagos. Quotes answered within one business day. Two Victoria Island showrooms.',
      path: '/contact/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-locations-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink, Icon, Reveal],
  template: `
    <ol-content-page
      heading="Showrooms"
      eyebrow="Victoria Island, Lagos"
      image="/images/pages/showrooms.webp"
      lede="Two on Idowu Martins Street, a few doors apart. Furniture is the category where a photograph helps least - come and sit on it before you commit."
      [wide]="true"
    >
      <!-- The two Lagos locations, photo-led. -->
      <ul class="-mt-20 grid gap-5 sm:-mt-24 lg:grid-cols-2">
        @for (location of locations; track location.name) {
          <li olReveal class="overflow-hidden rounded-3xl border border-ink-100 bg-white shadow-md">
            <div class="relative aspect-[16/9] overflow-hidden bg-brand-900">
              <img [src]="location.photo" [alt]="location.alt" width="600" height="450" loading="lazy" decoding="async" class="h-full w-full object-cover" />
              <span class="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-ink-900 shadow-sm backdrop-blur">
                <ol-icon name="store" [size]="14" class="text-brand-600" /> {{ location.kind }}
              </span>
            </div>
            <div class="p-6 sm:p-8">
              <h2 class="font-heading text-2xl font-semibold text-ink-900">{{ location.name }}</h2>
              <address class="mt-3 flex gap-2.5 not-italic text-ink-600">
                <ol-icon name="map-pin" [size]="18" class="mt-0.5 shrink-0 text-brand-600" />
                <span>{{ location.lines[0] }}<br />{{ location.lines[1] }}</span>
              </address>
              <p class="mt-3 text-ink-600">{{ location.what }}</p>
              <div class="mt-6 flex flex-wrap gap-3">
                <a [href]="location.directions" rel="noopener" class="inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
                  Get directions <ol-icon name="arrow-right" [size]="16" />
                </a>
                <a href="https://wa.me/2347074723165" rel="noopener" class="inline-flex items-center gap-2 rounded-full border border-ink-200 px-6 py-3 text-sm font-semibold text-ink-900 transition-colors hover:border-brand-600 hover:text-brand-700">
                  <ol-icon name="message-circle" [size]="16" /> Check what is on display
                </a>
              </div>
            </div>
          </li>
        }
      </ul>

      <!-- Before you travel. -->
      <section aria-labelledby="visit-tips" class="mt-16">
        <p class="ol-eyebrow">Before you travel</p>
        <h2 id="visit-tips" class="mt-1 font-heading text-2xl font-bold sm:text-3xl">Make the trip count</h2>
        <ul class="mt-6 grid gap-4 md:grid-cols-3">
          @for (tip of tips; track tip.title) {
            <li olReveal class="rounded-2xl border border-ink-100 bg-white p-6 shadow-xs">
              <span class="inline-flex rounded-xl bg-brand-50 p-3 text-brand-600"><ol-icon [name]="tip.icon" [size]="20" /></span>
              <h3 class="mt-4 font-heading text-base font-semibold text-ink-900">{{ tip.title }}</h3>
              <p class="mt-1 text-sm leading-relaxed text-ink-600">{{ tip.text }}</p>
            </li>
          }
        </ul>
      </section>

      <!-- Dubai, plainly: a registered office, not somewhere to visit. -->
      <section class="mt-10 flex flex-wrap items-start gap-4 rounded-2xl border border-dashed border-ink-200 bg-ink-50 p-6">
        <span class="inline-flex rounded-xl bg-white p-3 text-ink-600"><ol-icon name="building-2" [size]="20" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="font-heading text-base font-semibold text-ink-900">Dubai - registered office</h2>
          <p class="mt-1 text-sm text-ink-600">
            Office Land Solutions DMCC, Unit 808-001 Platinum Tower, Plot JLT-PH1-12, Jumeirah Lakes Towers,
            Dubai, UAE. Not a showroom - there is nothing to visit and no counter. Listed because it appears
            on company documentation and buyers ask.
          </p>
        </div>
      </section>
    </ol-content-page>
  `,
})
export class LocationsPage {
  private readonly seo = inject(SeoService);

  protected readonly locations = LOCATIONS;

  protected readonly tips: readonly { icon: IconName; title: string; text: string }[] = [
    { icon: 'message-circle', title: 'Message us first', text: 'Tell us on WhatsApp what you want to see. Display stock rotates, and we would rather say a chair is not on the floor today than have you find out on the street.' },
    { icon: 'ruler', title: 'Bring dimensions', text: 'For a fit-out, bring a floor plan or the room sizes. PVC doors and interiors are quoted from a measure - a plan turns a conversation into a quotation.' },
    { icon: 'file-text', title: 'Bring the list', text: 'If you are buying for an organisation, bring the requisition. We can price it while you look.' },
  ];

  constructor() {
    this.seo.apply({
      title: [
        'Office Furniture Showrooms in Victoria Island, Lagos',
        'Showrooms - Victoria Island, Lagos',
        'Showrooms',
      ],
      description:
        'Two OfficeLand showrooms on Idowu Martins Street, Victoria Island, Lagos. See office furniture before you commit. Directions and what to bring.',
      path: '/locations/',
      indexable: true,
      canonicalOverride: null,
    });

    /*
     * The two Lagos addresses as FurnitureStore nodes - the substitute for a
     * map on the page (D-053 rules out a Maps iframe), and the better half of
     * that trade: the markup is what puts the business in the local pack.
     * Dubai is absent on purpose (Q14).
     */
    this.seo.setStructuredData([
      localBusinessSchema({
        name: 'Head Office',
        street: 'Century 21 Mall, 14B Idowu Martins Street',
        locality: 'Victoria Island',
        region: 'Lagos',
        url: '/locations/',
      }),
      localBusinessSchema({
        name: 'Furniture Showroom',
        street: 'No 18, Idowu Martins Street',
        locality: 'Victoria Island',
        region: 'Lagos',
        url: '/locations/',
      }),
    ]);
  }
}
