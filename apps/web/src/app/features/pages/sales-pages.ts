import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ContentPage } from './content-page';
import { SeoService } from '../../core/seo/seo.service';

/**
 * Bulk supply, buying guides and quote tracking.
 *
 * ── /quote/track/ is honest about not being self-service ─────────────────
 * The header and footer have advertised "Track my quote" since Phase 7. There
 * is no tracking endpoint, and building one properly is not a small job: a
 * lookup keyed on the reference alone lets anybody enumerate other people's
 * quotations, so it needs the reference AND the email it was sent to, rate
 * limiting, and a decision about what a wrong pair should reveal.
 *
 * Half-building that would be worse than not having it. So this page says
 * plainly how to check on a quote today - which is a real answer, because the
 * quote reference and two staffed channels genuinely do resolve it - and the
 * self-service lookup stays a scoped piece of work rather than a link that
 * lies.
 */

@Component({
  selector: 'ol-bulk-supply-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Bulk &amp; tender supply"
      lede="Send the schedule. We price it line by line, state lead times per item, and supply the documentation a tender asks for."
    >
      <h2>Send us the schedule</h2>
      <p>
        You do not need to find anything on this site first. A bill of quantities, a
        requisition list, a spreadsheet, a scanned tender document or a photograph of
        a printed schedule are all things we can price from. Send it to
        <a href="mailto:sales@officelandng.com">sales&#64;officelandng.com</a> or through
        the <a routerLink="/quote/">quote form</a>.
      </p>
      <p>
        We price every line, including the ones we would substitute, and we say which
        is which. A quotation that silently swaps a specification is a quotation that
        fails at evaluation.
      </p>

      <h2>Who we supply</h2>
      <p>
        Ministries, agencies and parastatals; schools, universities and their
        bursaries; hospitals and clinics; banks, and corporates running multi-site
        fit-outs. Between them they account for most of what leaves our warehouse.
      </p>

      <h2>Documentation</h2>
      <p>
        Public-sector procurement usually asks for more than a price. We can supply
        company registration and tax documentation, our Standards Organisation of
        Nigeria certification, authorised-reseller confirmation for the brands
        concerned, and references from comparable supplies. Tell us what the tender
        requires and we will assemble it with the quotation rather than after the
        deadline.
      </p>

      <h2>Phased delivery</h2>
      <p>
        Large orders rarely want to arrive at once, and buildings rarely have room for
        them to. We will schedule delivery in phases against your programme - by
        floor, by site, by term - and hold the balance. Say so at quotation
        stage, because it changes how the order is planned rather than how it is
        delivered.
      </p>

      <h2>What we need from you</h2>
      <ul>
        <li>Quantities, and whether they are firm or indicative.</li>
        <li>The delivery address or addresses, and access constraints.</li>
        <li>The date it has to be on site, and whether that date is a deadline or a target.</li>
        <li>Any specification you are obliged to match exactly.</li>
      </ul>
      <p>
        The last one matters more than it looks. "Or equivalent" and "as specified"
        lead to two different quotations, and only one of them survives evaluation.
      </p>
    </ol-content-page>
  `,
})
export class BulkSupplyPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: [
        'Bulk & Tender Supply for Government, Schools and Corporates',
        'Bulk & tender supply',
      ],
      description:
        'OfficeLand prices requisition schedules and tender documents line by line, supplies procurement documentation, and delivers in phases against your programme.',
      path: '/bulk-supply/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-guides-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Buying guides"
      lede="What to settle before you order, by category. Written for somebody buying for an organisation rather than for themselves."
    >
      <p>
        Most buying mistakes in this catalogue are made at specification rather than
        at purchase - a UPS sized by the wrong number, a camera that cannot
        resolve a face at the distance it is mounted, a wardrobe built against a wall
        that carries moisture. The guidance below sits on the category pages
        themselves, next to the products it applies to, rather than in an article
        somebody has to find first.
      </p>

      <h2>Where the guidance lives</h2>
      <ul>
        <li>
          <a routerLink="/c/smart-tech/power-backup/">Power &amp; backup</a> -
          why a UPS is sized by load and runtime rather than by a VA figure, why
          battery capacity and not inverter rating decides how long the lights stay
          on, and what a stabiliser is actually protecting.
        </li>
        <li>
          <a routerLink="/c/pvc-doors-interiors/">PVC doors &amp; interiors</a> -
          where moisture and termites actually attack a fit-out, and when a cheaper
          board is the honest answer.
        </li>
        <li>
          <a routerLink="/c/smart-tech/security-surveillance/">Security &amp;
          surveillance</a> - specifying by what you need to be able to prove,
          and why the recording decision matters more than the camera count.
        </li>
        <li>
          <a routerLink="/c/smart-tech/conferencing-av/">Conferencing &amp; AV</a>
          - sizing a room, and why audio decides whether a meeting room gets
          used.
        </li>
        <li>
          <a routerLink="/c/office-furniture/seating/">Seating</a> and
          <a routerLink="/c/office-furniture/desks-tables/">desks</a> - what to
          check in person before committing to a quantity.
        </li>
      </ul>

      <h2>Standalone guides</h2>
      <p>
        Longer guides - specifying a floor of workstations, sizing backup for a
        building, running a furniture tender - are being written and will appear
        here. We would rather publish five that are genuinely useful than twenty that
        restate a product page.
      </p>
      <p>
        If there is a decision you are stuck on now, it is faster to
        <a routerLink="/quote/">ask us</a> than to wait for the guide.
      </p>
    </ol-content-page>
  `,
})
export class GuidesPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Buying Guides - Office Equipment in Nigeria', 'Buying guides'],
      description:
        'What to settle before ordering office equipment: sizing power backup, specifying surveillance, choosing conferencing kit and fit-out materials.',
      path: '/guides/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-quote-track-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Track my quote"
      lede="Quote your reference and we can tell you exactly where it is."
    >
      <h2>Your reference</h2>
      <p>
        Every quote request gets a reference the moment it is received, and it is on
        the confirmation email we send back. Quote it in any message about the
        enquiry and whoever picks up can see the whole thread without asking you to
        repeat yourself.
      </p>
      <p>
        No confirmation email? Check spam first - if it is not there, the
        request may not have reached us, and it is worth
        <a routerLink="/quote/">sending it again</a>.
      </p>

      <h2>Checking on it</h2>
      <p>
        The fastest route is
        <a href="https://wa.me/2347074723165" rel="noopener">WhatsApp</a> during
        business hours, with your reference. Otherwise reply to the confirmation
        email - that keeps everything on one thread - or email
        <a href="mailto:sales@officelandng.com">sales&#64;officelandng.com</a>, or call
        <a href="tel:+2347074723165">+234 707 472 3165</a>.
      </p>

      <h2>What to expect, and when</h2>
      <p>
        Quotes are answered within one business day. Most are answered the same day.
        A request that will take longer - a fit-out needing a site measure, a
        tender schedule running to hundreds of lines, an item we have to price from a
        supplier - gets an acknowledgement inside that day telling you when the
        full quotation will arrive, so the silence is never the answer.
      </p>
      <p>
        If a business day has passed with nothing at all, assume the message went
        astray rather than that you are being ignored, and chase us on WhatsApp.
      </p>

      <h2>Looking it up yourself</h2>
      <p>
        There is no self-service lookup on this site yet, and we would rather say so
        than put up a form that cannot answer. A quotation contains commercial
        pricing, so a lookup has to be able to prove you are the person who asked for
        it - that is being built properly rather than quickly.
      </p>
    </ol-content-page>
  `,
})
export class QuoteTrackPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Track My Quote', 'Track my quote'],
      description:
        'How to check on an OfficeLand quote request: your reference, the fastest channel, and what response time to expect.',
      path: '/quote/track/',
      // Indexable: it answers a real query ("track my quote officeland") and
      // contains nothing visitor-specific. It is a help page, not an account
      // page.
      indexable: true,
      canonicalOverride: null,
    });
  }
}
