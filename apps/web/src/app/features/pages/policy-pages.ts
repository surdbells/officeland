import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ContentPage } from './content-page';
import { SeoService } from '../../core/seo/seo.service';

/**
 * Privacy, Terms and Cookies.
 *
 * ── These are drafts and need a lawyer before launch ─────────────────────
 * They are written to describe what this application ACTUALLY does — the data
 * it collects, where it goes, how long it is kept — which is the part an
 * engineer can state accurately and a template cannot. A generic policy
 * copied from elsewhere would be worse than useless: it would describe
 * processing that does not happen and omit processing that does.
 *
 * What they are not is legal advice, and nobody here is qualified to sign
 * them off. Before launch they need review against the Nigeria Data
 * Protection Act 2023 by somebody who is. Flagged in docs/DECISIONS.md.
 *
 * ── Accuracy notes for whoever reviews them ──────────────────────────────
 * Verified against the code, not assumed:
 *   • The basket is a cookie holding an opaque identifier (Phase 9a/9b).
 *   • Quote submissions are stored and emailed via ZeptoMail (Phase 10).
 *   • There is no analytics, no advertising pixel and no third-party script:
 *     the CSP is `default-src 'self'` with no external origins (D-053), so a
 *     tracker could not load even if one were added by accident.
 *   • Bounce webhooks and a suppression list exist (Phase 10d/10g).
 * If any of that changes, this page changes with it.
 */

@Component({
  selector: 'ol-privacy-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Privacy &amp; data protection"
      lede="What we collect, why, and how long we keep it. Written against the Nigeria Data Protection Act 2023."
    >
      <p>
        OfficeLand Limited is the data controller for personal data collected through
        this site. Questions, requests and complaints:
        <a href="mailto:support@officelandng.com">support&#64;officelandng.com</a>.
      </p>

      <h2>What we collect</h2>
      <p>
        <strong>When you request a quote:</strong> your name, organisation, email
        address, phone number, delivery location and whatever you tell us about what
        you need. This is the only place on the site where we ask for personal data,
        and every field we ask for is one we need in order to price and deliver an
        order.
      </p>
      <p>
        <strong>When you use the quote basket:</strong> a cookie holding a random
        identifier, so the items you have added survive a page reload. It contains no
        personal data and identifies a basket rather than a person.
      </p>
      <p>
        <strong>When you visit any page:</strong> our servers log the request &mdash;
        address, page, timestamp, browser &mdash; as any web server does. These logs
        exist to keep the site running and secure, and are not used to build a profile
        of you.
      </p>
      <p>
        We do not run analytics, advertising pixels or third-party trackers of any
        kind. This is enforced technically as well as by policy: the site&rsquo;s
        content security policy permits no external scripts, so one could not load
        even if it were added by mistake.
      </p>

      <h2>Why we are allowed to hold it</h2>
      <p>
        For quote requests, because you asked us for a quotation and we cannot
        provide one without it &mdash; the lawful basis is the steps taken at your
        request before entering a contract. For server logs and security records, our
        legitimate interest in operating a site that works and is not being attacked.
        For marketing email, your consent, which you give separately and can withdraw
        at any time.
      </p>

      <h2>Who else sees it</h2>
      <ul>
        <li>
          <strong>Our email provider</strong> sends quotations and confirmations on our
          behalf, and therefore processes your name and email address.
        </li>
        <li>
          <strong>Carriers</strong>, where an order is delivered outside Lagos, receive
          the delivery name, address and phone number &mdash; the minimum needed to
          deliver it.
        </li>
        <li>
          <strong>Nobody else.</strong> We do not sell personal data, and we do not
          share it for anyone else&rsquo;s marketing.
        </li>
      </ul>
      <p>
        Some of these providers operate outside Nigeria. Where data is transferred, it
        is transferred under the safeguards the Act requires.
      </p>

      <h2>How long we keep it</h2>
      <p>
        Quote requests and the correspondence around them are kept while the
        relationship is live and afterwards for as long as we may need them for tax,
        audit and warranty purposes. Basket cookies expire on their own. Server logs
        are rotated on a short cycle. Marketing consent is kept until you withdraw it,
        along with the record of the withdrawal itself &mdash; that record is how we
        avoid contacting you again.
      </p>

      <h2>Your rights</h2>
      <p>
        Under the Nigeria Data Protection Act 2023 you may ask for a copy of the
        personal data we hold about you, ask us to correct it, ask us to delete it,
        object to processing, or withdraw a consent you have given. Email
        <a href="mailto:support@officelandng.com">support&#64;officelandng.com</a> and we
        will respond within the statutory period.
      </p>
      <p>
        Deletion has limits worth stating plainly: we cannot delete records we are
        required to keep, such as invoices, and asking us to delete an active quote
        request means we can no longer quote for it.
      </p>
      <p>
        If you are not satisfied with our response, you may complain to the Nigeria
        Data Protection Commission.
      </p>

      <h2>Security</h2>
      <p>
        The site is served over HTTPS. Administrative access requires two-factor
        authentication, is restricted by role, and every action against a record is
        logged. If a breach occurs that is likely to affect you, we will notify you
        and the Commission as the Act requires.
      </p>

      <h2>Changes</h2>
      <p>
        If what we do with data changes, this page changes with it. See also
        <a routerLink="/cookies/">cookies</a> and <a routerLink="/terms/">terms</a>.
      </p>
    </ol-content-page>
  `,
})
export class PrivacyPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Privacy & Data Protection (NDPA)', 'Privacy & data protection', 'Privacy'],
      description:
        'What personal data OfficeLand collects, why, who else sees it, how long it is kept, and your rights under the Nigeria Data Protection Act 2023.',
      path: '/privacy/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-terms-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Terms"
      lede="What this site is, what a quotation commits us to, and what an order commits you to."
    >
      <h2>This site is a catalogue, not a shop</h2>
      <p>
        Nothing here is offered for sale at a stated price. The site describes what we
        supply so you can tell us what you need; the quote basket assembles a list to
        send us, and sending it places no order and costs nothing.
      </p>
      <p>
        Product information, specifications and images are given in good faith and are
        frequently supplied by manufacturers. Specifications change without notice.
        Where a detail matters to your decision, confirm it on the quotation, which is
        the document we stand behind.
      </p>

      <h2>Quotations</h2>
      <p>
        A quotation is an offer to supply on the terms it states. It holds for the
        period stated on it, and after that we may need to requote &mdash; not as a
        tactic but because exchange rates and supplier pricing move.
      </p>
      <p>
        Lead times on a quotation are our best estimate at the time of quoting and are
        confirmed at order. Where a lead time is critical, say so and we will treat it
        as a condition rather than an estimate.
      </p>

      <h2>Orders</h2>
      <p>
        An order exists when you accept a quotation in writing &mdash; a purchase
        order, a signed quotation, or an email confirming it &mdash; and we acknowledge
        it. Once fabrication or a special order has started, cancellation may not be
        possible; where that applies we say so on the quotation before you commit.
      </p>
      <p>
        Payment terms are stated on the quotation. Credit terms, where offered, are
        agreed in advance and in writing.
      </p>

      <h2>Delivery, returns and warranty</h2>
      <p>
        Set out on their own pages, and they form part of these terms:
        <a routerLink="/delivery/">delivery and lead times</a>,
        <a routerLink="/returns/">returns</a>, <a routerLink="/warranty/">warranty</a>.
      </p>

      <h2>Liability</h2>
      <p>
        We are responsible for supplying goods that match the quotation and for work we
        carry out. We are not responsible for losses that were not foreseeable when the
        order was placed, or for a failure caused by something outside our reasonable
        control. Nothing here limits liability that cannot lawfully be limited.
      </p>

      <h2>Using this site</h2>
      <p>
        The content of this site &mdash; text, structure and images we own &mdash;
        belongs to OfficeLand Limited. Brand names and logos belong to their owners and
        appear here to identify what we supply. You may not scrape, republish or resell
        the catalogue.
      </p>

      <h2>Governing law</h2>
      <p>
        These terms are governed by the laws of the Federal Republic of Nigeria and are
        subject to the jurisdiction of its courts.
      </p>
    </ol-content-page>
  `,
})
export class TermsPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Terms of Supply', 'Terms'],
      description:
        'OfficeLand terms: what the catalogue is, how quotations and lead times work, when an order is binding, and how liability is handled.',
      path: '/terms/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-cookies-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Cookies"
      lede="Two cookies, both needed to make the site work. No tracking, and no banner, because there is nothing to consent to."
    >
      <p>
        Most sites open with a consent banner because most sites set cookies you would
        reasonably decline. This one does not, so there is no banner &mdash; asking
        permission for something you cannot switch off is theatre.
      </p>

      <h2>What is set</h2>
      <ul>
        <li>
          <strong>Quote basket.</strong> A random identifier so the items you add
          survive a page reload. It holds no personal data, identifies a basket rather
          than a person, and expires on its own. Without it the basket empties on every
          page.
        </li>
        <li>
          <strong>Session and security.</strong> Standard cookies that keep a form
          submission attached to the browser that started it and protect against
          cross-site request forgery. These exist to stop the quote form being abused.
        </li>
      </ul>

      <h2>What is not set</h2>
      <p>
        No analytics, no advertising, no social media pixels, no cross-site tracking,
        and no third-party cookies of any kind. We do not know which pages you visited
        before this one or which you visit after.
      </p>
      <p>
        This is enforced technically rather than merely promised: the site&rsquo;s
        content security policy allows no external scripts or connections, so a tracker
        could not run here even if somebody added one by mistake.
      </p>

      <h2>Refusing them</h2>
      <p>
        Your browser can block or delete cookies for this site at any time, and nothing
        will break except the basket, which will forget its contents between pages. You
        can still request a quote &mdash; the form works without it.
      </p>

      <h2>Related</h2>
      <p>
        <a routerLink="/privacy/">Privacy and data protection</a> covers the personal
        data you give us when you request a quote.
      </p>
    </ol-content-page>
  `,
})
export class CookiesPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Cookies', 'Cookie policy'],
      description:
        'OfficeLand sets two functional cookies and no trackers. What each one does, and what happens if you block them.',
      path: '/cookies/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}
