import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ContentPage } from './content-page';
import { Icon } from '../../shared/icon';
import { SeoService } from '../../core/seo/seo.service';
import { localBusinessSchema } from '../../core/seo/structured-data';

/**
 * About, Contact and Showrooms.
 *
 * Three components in one file because they are one lazy-loaded chunk of
 * static text - roughly 4 KB between them. Splitting them into three chunks
 * would add two round trips to save nothing.
 *
 * These are indexable. Unlike the shop page and the basket, they are the
 * pages a search for "office furniture showroom Victoria Island" should
 * land on, and they carry the entity information (address, phone, opening
 * pattern) that a local search result is assembled from.
 */

@Component({
  selector: 'ol-about-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="About OfficeLand"
      lede="We supply and install office furniture, IT equipment and consumables for businesses, schools and government across Nigeria."
    >
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
        is not the price of one chair collected from a shop, and pretending otherwise
        wastes everyone&rsquo;s time. Quotes are answered within one business day,
        with lead times stated per line rather than averaged across the order.
      </p>

      <h2>What we supply</h2>
      <p>
        Nine departments: office furniture, IT products, smart tech and power backup,
        printers and consumables, office supplies, paper and notebooks, filing and
        storage, school supplies, and PVC doors and interiors. The last of those is a
        fit-out business rather than a catalogue - doors, wardrobes, kitchens
        and panelling made to a site measure.
      </p>
      <p>
        We list a fraction of what we can supply. If something is not on the site,
        <a routerLink="/quote/">ask</a>: the catalogue reflects what is worth
        maintaining a page for, not the limit of what we source.
      </p>

      <h2>Standards and accreditation</h2>
      <p>
        We are certified by the Standards Organisation of Nigeria. For institutional
        and government buyers this is frequently a qualification requirement rather
        than a badge, and we can supply the documentation with a quotation when a
        tender asks for it.
      </p>

      <h2>Installation</h2>
      <p>
        Furniture and fit-out work is installed by our own people in Lagos and quoted
        per site elsewhere. We would rather install what we supply: a chair assembled
        badly is a warranty claim, and a wardrobe fitted to an out-of-level floor is a
        wardrobe that binds within months.
      </p>

      <p>
        <a routerLink="/locations/">Visit a showroom</a> to see furniture before you
        commit, or <a routerLink="/bulk-supply/">read how we handle bulk and tender
        supply</a>.
      </p>
    </ol-content-page>
  `,
})
export class AboutPage {
  private readonly seo = inject(SeoService);

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
  imports: [ContentPage, RouterLink, Icon],
  template: `
    <ol-content-page
      heading="Contact"
      lede="Quotes answered within one business day. For anything urgent, WhatsApp is the fastest route to a person."
    >
      <h2>Get a price</h2>
      <p>
        The quickest way to a number is to
        <a routerLink="/quote/">send us the list</a> - a requisition schedule, a
        drawing, a photograph of what you need to match, or a description. You do not
        need to find the products on this site first.
      </p>

      <h2>Reach us directly</h2>
      <ul class="not-prose space-y-3">
        <li class="flex items-center gap-2.5">
          <ol-icon name="message-circle" [size]="18" class="text-brand-600" />
          <a href="https://wa.me/2347074723165" rel="noopener">WhatsApp +234 707 472 3165</a>
        </li>
        <li class="flex items-center gap-2.5">
          <ol-icon name="phone" [size]="18" class="text-brand-600" />
          <a href="tel:+2347074723165">+234 707 472 3165</a>
        </li>
        <li class="flex items-center gap-2.5">
          <ol-icon name="mail" [size]="18" class="text-brand-600" />
          <a href="mailto:sales@officelandng.com" class="ol-wrap-anywhere">sales&#64;officelandng.com</a>
          - quotes, orders, tenders
        </li>
        <li class="flex items-center gap-2.5">
          <ol-icon name="mail" [size]="18" class="text-brand-600" />
          <a href="mailto:support@officelandng.com" class="ol-wrap-anywhere">support&#64;officelandng.com</a>
          - deliveries, warranty, anything after the sale
        </li>
      </ul>

      <h2>Come in</h2>
      <p>
        Two addresses on Victoria Island, both on Idowu Martins Street. See
        <a routerLink="/locations/">showrooms and directions</a>.
      </p>

      <h2>What to include</h2>
      <p>
        Whatever the channel, three things get you a faster and firmer answer: the
        quantity, the delivery address, and the date you need it by. Lead time is the
        variable that most often changes a recommendation, and it is the one most
        enquiries leave out.
      </p>
    </ol-content-page>
  `,
})
export class ContactPage {
  private readonly seo = inject(SeoService);

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
  imports: [ContentPage, RouterLink, Icon],
  template: `
    <ol-content-page
      heading="Showrooms"
      lede="Two on Victoria Island, a few doors apart. Worth the trip before committing to furniture."
    >
      <p>
        Furniture is the category where a photograph is least useful. Seat depth,
        the give in a backrest, the finish on a desk edge and how a drawer runs are
        all things you decide in ten seconds in person and cannot decide at all from a
        specification sheet. If the order matters, come and sit on it.
      </p>

      <h2>Head Office</h2>
      <p class="not-prose">
        Century 21 Mall, 14B Idowu Martins Street,<br />
        Victoria Island, Lagos
      </p>
      <p>
        Sales, quotations and the stationery, IT and consumables counter.
        <a
          href="https://www.google.com/maps/search/?api=1&amp;query=Century+21+Mall+14B+Idowu+Martins+Street+Victoria+Island+Lagos"
          rel="noopener"
        >Get directions</a>
      </p>

      <h2>Furniture Showroom</h2>
      <p class="not-prose">
        No 18, Idowu Martins Street,<br />
        Victoria Island, Lagos State
      </p>
      <p>
        Chairs, desks, workstations, conference tables and storage on display.
        <a
          href="https://www.google.com/maps/search/?api=1&amp;query=18+Idowu+Martins+Street+Victoria+Island+Lagos"
          rel="noopener"
        >Get directions</a>
      </p>

      <h2>Dubai</h2>
      <p class="not-prose">
        Office Land Solutions DMCC, Unit 808-001 Platinum Tower,<br />
        Plot JLT-PH1-12, Jumeirah Lakes Towers, Dubai, UAE
      </p>
      <p>
        A registered office, not a showroom - there is nothing to visit and no
        counter. It is listed because it appears on company documentation and buyers
        ask.
      </p>

      <h2>Before you travel</h2>
      <p>
        Message us on <a href="https://wa.me/2347074723165" rel="noopener">WhatsApp</a>
        with what you want to see. Display stock rotates, and we would rather tell you
        a particular chair is not on the floor today than have you find out on Idowu
        Martins Street.
      </p>
      <p>
        If you are coming to specify a fit-out, bring dimensions or a floor plan.
        <a routerLink="/c/pvc-doors-interiors/">PVC doors and interiors</a> are quoted
        from a measure, and a plan turns a conversation into a quotation.
      </p>
    </ol-content-page>
  `,
})
export class LocationsPage {
  private readonly seo = inject(SeoService);

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
     * The two Lagos addresses as FurnitureStore nodes.
     *
     * This is the substitute for a map on the page. D-053 rules out a Maps
     * iframe, and this is the better half of that trade anyway: the markup is
     * what puts the business on Google's own map and in the local pack, which
     * is where "office furniture showroom Victoria Island" is actually
     * searched. A map on a page somebody has already found helps less.
     *
     * Dubai is absent on purpose - a registered office whose trading status
     * is unresolved (Q14), and asserting LocalBusiness there tells Google the
     * business serves UAE customers.
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
