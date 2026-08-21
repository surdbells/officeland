{{--
    The customer's confirmation receipt (§10e, D-006).

    ── Why markdown mail rather than a hand-built table layout ──────────────
    Laravel's markdown mail compiles to the table-based, inlined-CSS HTML that
    Outlook still requires, and produces the plain-text alternative from the
    same source. Two hand-maintained bodies drift, and the one that drifts is
    always the text one, because nobody looks at it.

    ── Dark mode ────────────────────────────────────────────────────────────
    No hardcoded background or text colours are introduced here. Gmail and
    Outlook invert what they choose to; a template that fixes #fff on a wrapper
    and inherits its text colour is exactly how a receipt ends up as white text
    on white. Sticking to the default component palette keeps it legible in
    both.
--}}
<x-mail::message>
# Thank you — we have your request

Your reference is **{{ $quote->reference }}**. Quote it if you call or reply, and
we will find this in seconds.

**We respond within one business day.** A member of the sales team will price
everything below, confirm availability and lead times, and reply to this
address.

## What you asked for

<x-mail::table>
| Qty | Item | Details |
| :-- | :--- | :------ |
@foreach ($items as $item)
| {{ $item->qty }} | {{ $item->product_name_snapshot }} | {{ collect([$item->brand_name_snapshot, $item->mpn_snapshot, $item->customer_note])->filter()->implode(' · ') ?: '—' }} |
@endforeach
</x-mail::table>

@if ($quote->message)
## Your note

{{ $quote->message }}
@endif

<x-mail::button :url="config('app.public_origin').'/c/'">
Keep browsing the catalogue
</x-mail::button>

## If you need us sooner

Call **+234 707 472 3165** or reply to this email. Quote {{ $quote->reference }}
and we will pick it up from here.

Thanks,<br>
{{ config('app.name') }}

<x-slot:subcopy>
You are receiving this because a quote request was submitted from
{{ config('app.public_origin') }} using this email address. We use your details
to respond to this request and nothing else.
</x-slot:subcopy>
</x-mail::message>
