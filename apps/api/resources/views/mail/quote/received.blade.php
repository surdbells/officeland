{{--
    The internal notification (§10e).

    Written to be actionable from a phone: reference, contact details and the
    full list, in that order. A salesperson reading this in a car park should
    be able to call the customer back without opening the admin panel.

    Reply-to is the customer, so answering the lead is one tap.
--}}
<x-mail::message>
# New quote request {{ $quote->reference }}

**{{ $quote->email }}** &nbsp;·&nbsp; **{{ $quote->phone }}**

Submitted {{ $quote->submitted_at?->format('D j M Y, H:i') ?? 'just now' }}.
Reply to this email to answer the customer directly.

## Items

<x-mail::table>
| Qty | Item | SKU / MPN | Note |
| :-- | :--- | :-------- | :--- |
@foreach ($items as $item)
| {{ $item->qty }} | {{ $item->product_name_snapshot }}{{ $item->free_text_description ? ' *(not in catalogue)*' : '' }} | {{ collect([$item->sku_snapshot, $item->mpn_snapshot])->filter()->implode(' / ') ?: '—' }} | {{ $item->customer_note ?: '—' }} |
@endforeach
</x-mail::table>

@if ($quote->message)
## Customer note

{{ $quote->message }}
@endif

<x-mail::button :url="config('app.url').'/admin/quotes/'.$quote->id">
Open in the admin panel
</x-mail::button>

@if ($quote->utm_source || $quote->referrer)
## Where it came from

@if ($quote->utm_source)
Campaign: {{ collect([$quote->utm_source, $quote->utm_medium, $quote->utm_campaign])->filter()->implode(' / ') }}
@endif
@if ($quote->landing_page)
Landing page: {{ $quote->landing_page }}
@endif
@if ($quote->referrer)
Referrer: {{ $quote->referrer }}
@endif
@endif

<x-slot:subcopy>
Sent to the sales inbox because a quote was submitted at
{{ config('app.public_origin') }}. This is an internal notification; the
customer has received their own confirmation separately.
</x-slot:subcopy>
</x-mail::message>
