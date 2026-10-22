<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ZeptoMailApiTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The ZeptoMail transport (§10a, §10b).
 *
 * ── Why the payload shape is asserted field by field ─────────────────────
 * ZeptoMail accepts a structurally wrong request with a 200 and delivers
 * nothing. Recipients must be wrapped in an `email_address` object, and the
 * bodies are `htmlbody`/`textbody` with no separator — neither matches any
 * other provider's convention. A typo here is not a crash; it is silence, and
 * silence on the quote pipeline is the failure the whole project is built to
 * avoid.
 *
 * These assertions are written against the live API documentation, as §10a
 * requires, rather than against the implementation.
 */
final class ZeptoMailTransportTest extends TestCase
{
    private const ENDPOINT = 'https://api.zeptomail.com/v1.1/email';

    private function transport(): ZeptoMailApiTransport
    {
        return new ZeptoMailApiTransport(
            http: $this->app->make(HttpFactory::class),
            token: 'test-token',
            endpoint: self::ENDPOINT,
        );
    }

    private function email(): Email
    {
        return (new Email)
            ->from(new Address('sales@officelandng.com', 'OfficeLand'))
            ->to(new Address('buyer@example.com', 'A Buyer'))
            ->subject('Your quote request OL-Q-2026-000001')
            ->html('<p>Thank you</p>')
            ->text('Thank you');
    }

    private function send(Email $email): ClientRequest
    {
        Http::fake([self::ENDPOINT => Http::response([
            'data' => [['code' => 'EM_104', 'message' => 'OK']],
            'request_id' => 'req-123',
            'object' => 'Email',
        ], 200)]);

        $this->transport()->send($email);

        $sent = null;
        Http::assertSent(function (ClientRequest $request) use (&$sent): bool {
            $sent = $request;

            return true;
        });

        /** @var ClientRequest $sent */
        return $sent;
    }

    public function test_it_posts_to_the_documented_endpoint(): void
    {
        $request = $this->send($this->email());

        $this->assertSame(self::ENDPOINT, $request->url());
        $this->assertSame('POST', $request->method());
    }

    /**
     * The scheme name is part of the value and case-sensitive: ZeptoMail wants
     * exactly "Zoho-enczapikey" (capital Z). Lowercase, or `Bearer`, returns a
     * 401/TM_4001 that reads exactly like a bad token even when the token is
     * correct - a slow thing to debug at go-live, as it was.
     */
    public function test_it_authenticates_with_the_zoho_enczapikey_prefix(): void
    {
        $request = $this->send($this->email());

        $this->assertSame('Zoho-enczapikey test-token', $request->header('Authorization')[0]);
    }

    public function test_it_sends_the_sender_as_an_address_and_name(): void
    {
        $body = $this->send($this->email())->data();

        $this->assertSame('sales@officelandng.com', $body['from']['address']);
        $this->assertSame('OfficeLand', $body['from']['name']);
    }

    /**
     * The nesting is ZeptoMail's, not ours. A flat {address,name} is accepted
     * with a 200 and delivered to nobody.
     */
    public function test_recipients_are_wrapped_in_an_email_address_object(): void
    {
        $body = $this->send($this->email())->data();

        $this->assertSame('buyer@example.com', $body['to'][0]['email_address']['address']);
        $this->assertSame('A Buyer', $body['to'][0]['email_address']['name']);
    }

    public function test_it_maps_both_bodies_to_the_documented_field_names(): void
    {
        $body = $this->send($this->email())->data();

        $this->assertSame('<p>Thank you</p>', $body['htmlbody']);
        $this->assertSame('Thank you', $body['textbody']);
    }

    public function test_it_maps_cc_bcc_and_reply_to(): void
    {
        $email = $this->email()
            ->cc(new Address('cc@example.com'))
            ->bcc(new Address('bcc@example.com'))
            ->replyTo(new Address('sales@officelandng.com'));

        $body = $this->send($email)->data();

        $this->assertSame('cc@example.com', $body['cc'][0]['email_address']['address']);
        $this->assertSame('bcc@example.com', $body['bcc'][0]['email_address']['address']);
        $this->assertSame('sales@officelandng.com', $body['reply_to'][0]['email_address']['address']);
    }

    /**
     * §10b. The quote reference travels into ZeptoMail's own logs, so "did
     * OL-Q-2026-000042 ever go out" is answerable without correlating
     * timestamps by hand.
     */
    public function test_the_reference_header_becomes_client_reference(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader(ZeptoMailApiTransport::REFERENCE_HEADER, 'OL-Q-2026-000042');

        $body = $this->send($email)->data();

        $this->assertSame('OL-Q-2026-000042', $body['client_reference']);
    }

    /** The reference header is consumed, not forwarded as a MIME header too. */
    public function test_the_reference_header_is_not_repeated_in_mime_headers(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader(ZeptoMailApiTransport::REFERENCE_HEADER, 'OL-Q-2026-000042');

        $body = $this->send($email)->data();

        $mime = $body['mime_headers'] ?? [];

        foreach (array_keys($mime) as $name) {
            $this->assertNotSame(
                ZeptoMailApiTransport::REFERENCE_HEADER,
                strtolower((string) $name),
            );
        }
    }

    /**
     * Tracking is off unless asked for: an open pixel and rewritten links on a
     * transactional receipt are a privacy cost the customer did not agree to,
     * and rewritten links get receipts flagged as phishing.
     */
    public function test_tracking_is_absent_by_default(): void
    {
        $body = $this->send($this->email())->data();

        $this->assertArrayNotHasKey('track_opens', $body);
        $this->assertArrayNotHasKey('track_clicks', $body);
    }

    public function test_a_failed_send_throws_rather_than_returning_quietly(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'error' => [
                'code' => 'TM_3201',
                'message' => 'Invalid input',
                'details' => [['code' => 'TM_3301', 'message' => 'is not a valid email', 'target' => 'to']],
            ],
        ], 400)]);

        $this->expectException(TransportException::class);
        // The useful part is nested in details; the top-level message is
        // frequently just "Invalid input".
        $this->expectExceptionMessageMatches('/to: is not a valid email/');

        $this->transport()->send($this->email());
    }

    /**
     * Symfony refuses a wholly empty message before any transport sees it, but
     * it permits an ATTACHMENTS-ONLY one — and ZeptoMail still requires
     * htmlbody or textbody. That combination reaches this transport and would
     * otherwise be a 400 naming no template, so it is caught here where the
     * message is still identifiable.
     */
    public function test_it_refuses_a_message_with_attachments_but_no_body(): void
    {
        $email = (new Email)
            ->from(new Address('sales@officelandng.com'))
            ->to(new Address('buyer@example.com'))
            ->subject('Attachment only')
            ->attach('file contents', 'list.csv', 'text/csv');

        Http::fake([self::ENDPOINT => Http::response([], 200)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/neither an HTML nor a text body/');

        $this->transport()->send($email);
    }
}
