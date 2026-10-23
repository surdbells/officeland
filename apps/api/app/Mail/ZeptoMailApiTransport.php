<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * ZeptoMail's HTTP sending API as a Symfony transport (§10a).
 *
 * ── Verified against the live documentation, as §10a requires ────────────
 * Endpoint      POST https://api.zeptomail.com/v1.1/email
 *               (EU tenants: https://api.zeptomail.eu/v1.1/email - Q18)
 * Authorization `zoho-enczapikey <token>` - NOT `Bearer`, and the prefix is
 *               part of the header value rather than a scheme name.
 * Body          from{address,name}, to[]{email_address{address,name}},
 *               cc[], bcc[], reply_to[], subject, htmlbody, textbody,
 *               mime_headers{}, client_reference, attachments[]
 * Success       200 with {data:[{code,message}], request_id, object:"Email"}
 * Failure       {error:{code,message,request_id,details:[{code,message,target}]}}
 *
 * The field names are the trap here: recipients are wrapped in an
 * `email_address` object rather than being flat, and the body fields are
 * `htmlbody`/`textbody` with no separator - neither matches the convention of
 * any other provider, and both fail silently by simply sending nothing.
 *
 * ── Why the API and not SMTP ─────────────────────────────────────────────
 * §13.2 asks for the API. It also gives us `client_reference`, which carries
 * the quote reference into ZeptoMail's own logs - so "did OL-Q-2026-000042
 * ever go out" is answerable without correlating timestamps by hand. That is
 * the question this business will actually ask.
 */
final class ZeptoMailApiTransport extends AbstractTransport
{
    /**
     * The header a Mailable sets to tie a send to its quote.
     *
     * Lower-cased because Symfony preserves the case it was given and header
     * names are case-insensitive.
     */
    public const REFERENCE_HEADER = 'x-officeland-reference';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $token,
        private readonly string $endpoint,
        private readonly int $timeout = 15,
        private readonly bool $trackOpens = false,
        private readonly bool $trackClicks = false,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            // A RawMessage carries pre-serialised MIME with no accessible
            // parts, so there is nothing to map onto the API's fields. Nothing
            // in this application produces one, and guessing would send an
            // empty message rather than fail.
            throw new TransportException('ZeptoMail'.'s API transport cannot send a pre-serialised raw message.');
        }

        $email = MessageConverter::toEmail($original);
        $envelope = $message->getEnvelope();

        $payload = $this->payload($email, $envelope);

        $response = $this->http
            ->timeout($this->timeout)
            ->withHeaders([
                // The scheme name is literally part of the value AND
                // case-sensitive: ZeptoMail wants exactly "Zoho-enczapikey"
                // (capital Z). Lowercase, or `Bearer`, is rejected as
                // TM_4001 "Invalid API Token" - a 401 that reads like a bad
                // token even when the token is perfect.
                'Authorization' => 'Zoho-enczapikey '.$this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($this->endpoint, $payload);

        if ($response->failed()) {
            /*
             * Thrown, not swallowed. The caller is a queued job with a retry
             * policy (§10c); returning quietly here would mark a failed send
             * as delivered and lose the lead silently - the exact failure the
             * whole quote pipeline exists to avoid.
             */
            throw new TransportException($this->describeFailure($response->status(), $response->json()));
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        $requestId = is_string($body['request_id'] ?? null) ? $body['request_id'] : null;

        if ($requestId !== null) {
            // ZeptoMail's own identifier, so a support conversation with them
            // can start from a value we already hold.
            $message->setMessageId($requestId);
        }

        // §10f. Deliberately structured rather than prose: this is the record
        // that answers "was it sent, to whom, and what did the provider say".
        Log::channel(config('mail.log_channel') ?? config('logging.default'))->info('mail.sent', [
            'provider' => 'zeptomail',
            'request_id' => $requestId,
            'to' => array_map(static fn (Address $a): string => $a->getAddress(), $envelope->getRecipients()),
            'subject' => $email->getSubject(),
            'client_reference' => $payload['client_reference'] ?? null,
            'status' => $response->status(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Email $email, Envelope $envelope): array
    {
        $from = $envelope->getSender();

        $payload = [
            'from' => [
                'address' => $from->getAddress(),
                'name' => $from->getName(),
            ],
            'to' => $this->recipients($email->getTo() ?: $envelope->getRecipients()),
            'subject' => (string) $email->getSubject(),
        ];

        // Build each optional recipient list and include it ONLY when it has a
        // real address. recipients() drops blank addresses, so a reply-to built
        // from an unset config value (an empty string) yields [] and is omitted
        // rather than sent as an empty field - which ZeptoMail rejects with
        // TM_3201 "Mandatory field found empty", failing the whole message.
        $cc = $this->recipients($email->getCc());
        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->recipients($email->getBcc());
        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        // reply_to has a DIFFERENT shape from to/cc/bcc on the native endpoint:
        // a flat {address, name}, not the {email_address: {...}} wrapper. Sending
        // the wrapper makes ZeptoMail look for `address` at the top level, not
        // find it, and reject the whole message with TM_3201 "reply_to:
        // Mandatory field found empty" - even when the address is perfectly valid.
        $replyTo = $this->replyToAddresses($email->getReplyTo());
        if ($replyTo !== []) {
            $payload['reply_to'] = $replyTo;
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $payload['htmlbody'] = $html;
        }

        if (is_string($text) && $text !== '') {
            $payload['textbody'] = $text;
        }

        /*
         * One of the two is mandatory. An empty send is accepted by nothing
         * and is worth catching here, where the message is still identifiable,
         * rather than as a 400 that names no template.
         */
        if (! isset($payload['htmlbody']) && ! isset($payload['textbody'])) {
            throw new TransportException('Refusing to send an email with neither an HTML nor a text body.');
        }

        $headers = $this->customHeaders($email);

        if ($headers !== []) {
            $payload['mime_headers'] = $headers;
        }

        $reference = $this->clientReference($email);

        if ($reference !== null) {
            $payload['client_reference'] = $reference;
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        /*
         * Tracking is off by default. An open pixel and rewritten links on a
         * transactional receipt are a privacy cost the customer did not agree
         * to, and rewritten links are a common reason a receipt is flagged as
         * phishing. Configurable because marketing may later want it on a
         * different mailer.
         */
        if ($this->trackOpens) {
            $payload['track_opens'] = true;
        }

        if ($this->trackClicks) {
            $payload['track_clicks'] = true;
        }

        return $payload;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<array{email_address: array{address: string, name?: string}}>
     */
    private function recipients(array $addresses): array
    {
        $recipients = [];

        foreach ($addresses as $address) {
            $email = trim($address->getAddress());

            // Drop a blank address. It reaches here when a reply-to / cc / bcc is
            // built from an empty value; ZeptoMail rejects the whole message
            // (TM_3201) if a recipient array carries an entry with no address.
            if ($email === '') {
                continue;
            }

            $entry = ['address' => $email];

            if ($address->getName() !== '') {
                $entry['name'] = $address->getName();
            }

            // The nesting is ZeptoMail's, not ours: a flat {address,name} is
            // accepted with a 200 and delivered to nobody.
            $recipients[] = ['email_address' => $entry];
        }

        return $recipients;
    }

    /**
     * reply_to on the native endpoint is a flat array of {address, name} - NOT
     * wrapped in an `email_address` object like to/cc/bcc. Blank addresses are
     * dropped so an unset reply-to never produces an empty field.
     *
     * @param  array<int, Address>  $addresses
     * @return list<array{address: string, name?: string}>
     */
    private function replyToAddresses(array $addresses): array
    {
        $out = [];

        foreach ($addresses as $address) {
            $email = trim($address->getAddress());

            if ($email === '') {
                continue;
            }

            $entry = ['address' => $email];

            if ($address->getName() !== '') {
                $entry['name'] = $address->getName();
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Custom headers, excluding everything the API sets itself.
     *
     * @return array<string, string>
     */
    private function customHeaders(Email $email): array
    {
        $reserved = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'subject',
            'content-type', 'mime-version', 'date', 'message-id',
            self::REFERENCE_HEADER,
        ];

        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());

            if (in_array($name, $reserved, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    private function clientReference(Email $email): ?string
    {
        $header = $email->getHeaders()->get(self::REFERENCE_HEADER);

        if ($header === null) {
            return null;
        }

        $value = trim($header->getBodyAsString());

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{content: string, mime_type: string, name: string}>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'content' => base64_encode($attachment->getBody()),
                'mime_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'name' => $attachment->getFilename() ?? 'attachment',
            ];
        }

        return $attachments;
    }

    /**
     * Turns the provider's error envelope into one line worth logging.
     *
     * ZeptoMail nests the useful part in `error.details[].message`, and the
     * top-level `message` is frequently just "Invalid input" - which tells
     * whoever is paged nothing at all.
     */
    private function describeFailure(int $status, mixed $body): string
    {
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        if (! is_array($error)) {
            return "ZeptoMail rejected the message with HTTP {$status}.";
        }

        $message = is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
        $code = is_string($error['code'] ?? null) ? $error['code'] : (string) $status;

        $details = [];

        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $target = is_string($detail['target'] ?? null) ? $detail['target'] : null;
            $text = is_string($detail['message'] ?? null) ? $detail['message'] : null;

            if ($text !== null) {
                $details[] = $target === null ? $text : "{$target}: {$text}";
            }
        }

        $suffix = $details === [] ? '' : ' ('.implode('; ', $details).')';

        return "ZeptoMail {$code}: {$message}{$suffix}";
    }

    public function __toString(): string
    {
        return 'zeptomail+api://'.parse_url($this->endpoint, PHP_URL_HOST);
    }
}
