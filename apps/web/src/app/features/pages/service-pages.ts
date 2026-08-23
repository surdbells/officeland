import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ContentPage } from './content-page';
import { SeoService } from '../../core/seo/seo.service';

/**
 * Delivery, Returns and Warranty — the three questions a buyer asks after
 * "how much", and the three a procurement officer has to answer to their own
 * finance department before raising an order.
 *
 * Written as commitments rather than as marketing. Every number here is one
 * the business has to keep, which is why there are fewer of them than a
 * competitor page would carry: a stated lead time that slips is worse than no
 * stated lead time.
 */

@Component({
  selector: 'ol-delivery-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Delivery &amp; lead times"
      lede="Lead times are quoted per line, not averaged across the order. Nationwide delivery, installation in Lagos."
    >
      <h2>How lead time is quoted</h2>
      <p>
        Every line on a quotation carries its own lead time. An order of chairs,
        toner and a fit-out is three different supply chains, and averaging them
        produces a date that is wrong for all three. If one line is holding the rest
        up we will say so on the quotation, so you can split the delivery rather than
        wait on the slowest item.
      </p>
      <p>
        Broadly: stationery and consumables we hold move fastest, IT and furniture
        depend on what is in stock against what is on order, and anything made to a
        measure &mdash; WPC doors, wardrobes, kitchens, panelling &mdash; is
        fabrication time plus installation and is quoted per project.
      </p>

      <h2>Where we deliver</h2>
      <p>
        Nationwide. Lagos deliveries are made by our own vehicles; outside Lagos we
        use carriers and the cost appears as a line on the quotation rather than
        being buried in the unit price. Remote sites and anything that needs a
        dedicated vehicle are priced per job.
      </p>

      <h2>Installation</h2>
      <p>
        Furniture assembly and fit-out installation are included on Lagos orders
        unless the quotation says otherwise, and quoted per site elsewhere. Tell us
        the floor, whether there is a service lift, and the access hours: a
        third-floor delivery into a building that only permits deliveries before
        9 a.m. is a different job from a ground-floor one, and finding that out on
        the day costs a return trip.
      </p>

      <h2>Receiving the delivery</h2>
      <p>
        Check the goods against the delivery note before signing. Damage in transit
        is straightforward to resolve when it is recorded at handover and difficult
        to resolve a week later, so note anything visible on the note itself, even if
        you have not opened every carton.
      </p>
      <p>
        Somebody with the authority to accept the delivery needs to be present. We
        will not leave an order with a security post unless you have told us in
        writing to do so.
      </p>

      <h2>Government and institutional orders</h2>
      <p>
        Ministries, schools and agencies frequently have delivery windows, gate
        procedures and documentation requirements that are not obvious from outside.
        Tell us about them at quotation stage and we will build them into the
        schedule &mdash; see <a routerLink="/bulk-supply/">bulk and tender supply</a>.
      </p>
    </ol-content-page>
  `,
})
export class DeliveryPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Delivery & Lead Times — Nationwide from Lagos', 'Delivery & lead times'],
      description:
        'How OfficeLand quotes lead times, delivers nationwide from Lagos, and handles installation and access. Lead times are stated per line.',
      path: '/delivery/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-returns-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Returns"
      lede="Tell us quickly, keep the packaging, and most returns are straightforward."
    >
      <h2>If something is wrong on arrival</h2>
      <p>
        Contact <a href="mailto:support@officelandng.com">support&#64;officelandng.com</a>
        or WhatsApp us with your quote reference and a photograph. Wrong item, damaged
        in transit, or short delivery: we collect and replace, and there is nothing for
        you to pay. Report it within a few days of delivery while it is still clearly a
        delivery problem rather than a wear problem.
      </p>

      <h2>If you ordered the wrong thing</h2>
      <p>
        Unused goods in their original packaging can usually go back. Ask before
        sending anything: we need to know it is coming, and some items &mdash;
        particularly IT with activated licences, opened consumables and anything
        made or cut to your specification &mdash; cannot be resold and cannot be
        taken back. A restocking charge and the collection cost may apply, and both
        will be stated before you commit to the return rather than after.
      </p>
      <p>
        This is the practical reason the packaging matters. A chair in its carton is
        stock; the same chair without one is a used chair.
      </p>

      <h2>What cannot be returned</h2>
      <ul>
        <li>Anything manufactured, cut or finished to your measure &mdash; WPC doors,
          wardrobes, kitchens and panelling are made for one site.</li>
        <li>Opened consumables: toner, ink, paper and cleaning products.</li>
        <li>Software and IT where a licence has been activated or a seal broken.</li>
        <li>Goods returned damaged, incomplete, or without their packaging.</li>
      </ul>
      <p>
        None of this affects your rights where goods are faulty. A fault is a
        <a routerLink="/warranty/">warranty</a> matter and is handled differently
        from a change of mind.
      </p>

      <h2>Bulk and tender orders</h2>
      <p>
        Large orders are quoted against a schedule and are frequently
        non-returnable once fabrication or a special order has started. Where that
        applies it is stated on the quotation, before you raise a purchase order,
        not in the small print afterwards.
      </p>
    </ol-content-page>
  `,
})
export class ReturnsPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Returns Policy', 'Returns'],
      description:
        'How to return goods to OfficeLand: wrong or damaged items, changes of mind, what cannot be returned, and how bulk orders differ.',
      path: '/returns/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}

@Component({
  selector: 'ol-warranty-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ContentPage, RouterLink],
  template: `
    <ol-content-page
      heading="Warranty"
      lede="The manufacturer sets the term. We are who you talk to when you need to use it."
    >
      <h2>What is covered</h2>
      <p>
        Warranty terms are set by the manufacturer and vary by product, so the term
        that applies to your order is the one stated on your quotation and invoice.
        As a rough guide, office furniture and IT hardware carry a manufacturer
        warranty of a year or more, and consumables carry none beyond being fit for
        use when supplied.
      </p>
      <p>
        A warranty covers a defect in manufacture or materials. It does not cover
        wear, accidental damage, misuse, or a failure caused by the environment the
        equipment is in &mdash; which in practice means the single most common
        rejected claim is electrical damage to equipment that was not protected.
        See <a routerLink="/c/smart-tech/power-backup/">power and backup</a>; a
        stabiliser costs a fraction of the equipment it protects.
      </p>

      <h2>Making a claim</h2>
      <p>
        Contact <a href="mailto:support@officelandng.com">support&#64;officelandng.com</a>
        with your quote or invoice reference, the serial number where there is one, and
        a description of what the fault is and when it started. A photograph or a short
        video of the fault occurring saves a visit more often than not.
      </p>
      <p>
        We handle the claim with the manufacturer rather than sending you to them.
        That is most of what buying through a supplier is for, and it matters most on
        brands where the authorised channel is the only channel that can honour a
        claim at all.
      </p>

      <h2>Authorised supply</h2>
      <p>
        We are an authorised reseller for the brands we carry, and we are certified by
        the Standards Organisation of Nigeria. Both matter for warranty specifically:
        equipment bought outside an authorised channel frequently has no valid
        warranty in Nigeria whatever the box says, and that is discovered at the point
        of claim.
      </p>

      <h2>Installation and warranty</h2>
      <p>
        Where we install, the installation is ours to put right. Where somebody else
        installs equipment we supplied, a fault caused by the installation is not a
        manufacturing defect &mdash; which is a large part of why we would rather
        install what we supply. See <a routerLink="/delivery/">delivery and
        installation</a>.
      </p>
    </ol-content-page>
  `,
})
export class WarrantyPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: ['Warranty — Claims and Cover', 'Warranty'],
      description:
        'How OfficeLand handles warranty claims: what manufacturer warranties cover, what they do not, and how to make a claim.',
      path: '/warranty/',
      indexable: true,
      canonicalOverride: null,
    });
  }
}
