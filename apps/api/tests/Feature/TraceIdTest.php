<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TraceId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TraceIdTest extends TestCase
{
    public function test_every_response_carries_a_trace_id(): void
    {
        $response = $this->getJson('/health/live');

        $traceId = $response->headers->get(TraceId::HEADER);

        $this->assertNotNull($traceId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $traceId,
        );
    }

    public function test_trace_ids_are_unique_per_request(): void
    {
        $first = $this->getJson('/health/live')->headers->get(TraceId::HEADER);
        $second = $this->getJson('/health/live')->headers->get(TraceId::HEADER);

        $this->assertNotSame($first, $second);
    }

    /**
     * A trace started by the storefront must survive into the API, otherwise
     * the two halves of a request cannot be correlated in the logs.
     */
    public function test_a_valid_inbound_trace_id_is_honoured(): void
    {
        $inbound = '11111111-2222-3333-4444-555555555555';

        $response = $this->withHeaders([TraceId::HEADER => $inbound])
            ->getJson('/health/live');

        $this->assertSame($inbound, $response->headers->get(TraceId::HEADER));
    }

    public function test_inbound_trace_id_is_normalised_to_lowercase(): void
    {
        $response = $this->withHeaders([TraceId::HEADER => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE'])
            ->getJson('/health/live');

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $response->headers->get(TraceId::HEADER));
    }

    /**
     * The header is attacker-controlled. An unvalidated value would let a
     * caller poison log correlation or inject terminal control sequences into
     * log output, so anything that is not a UUID is discarded and replaced.
     */
    #[DataProvider('malformedTraceIds')]
    public function test_a_malformed_inbound_trace_id_is_discarded(string $malformed): void
    {
        $response = $this->withHeaders([TraceId::HEADER => $malformed])
            ->getJson('/health/live');

        $traceId = $response->headers->get(TraceId::HEADER);

        $this->assertNotSame($malformed, $traceId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $traceId,
        );
    }

    /** @return array<string, array{string}> */
    public static function malformedTraceIds(): array
    {
        return [
            'not a uuid' => ['not-a-uuid'],
            'script injection' => ['<script>alert(1)</script>'],
            'ansi escape' => ["\033[31mred\033[0m"],
            'newline injection' => ["aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee\nfake log line"],
            'empty' => [''],
            'too short' => ['1111-2222'],
            'sql-ish' => ["' OR 1=1--"],
        ];
    }
}
