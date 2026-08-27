<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BasketResource;
use App\Models\Product;
use App\Models\QuoteBasket;
use App\Models\QuoteBasketItem;
use App\Quotes\BasketService;
use App\Quotes\BasketSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The quote basket (§9a, §9b).
 *
 * ── The server owns line-item identity ───────────────────────────────────
 * Every mutation names a product by SLUG or a line by UUID, and the server
 * resolves both. Nothing about a line - its name, its specification, its
 * allowed quantities - is accepted from the client, because all of it is
 * copied into an email that a salesperson acts on.
 *
 * ── Why a GET never creates a basket ─────────────────────────────────────
 * Reading an empty basket returns an empty basket, not a new row and a new
 * cookie. Otherwise every crawler that touches /api/v1/basket leaves one
 * behind, and the reaper has thousands of rows to clean up.
 */
final class BasketController extends Controller
{
    public function __construct(
        private readonly BasketSession $session,
        private readonly BasketService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $basket = $this->session->resolve($request);

        return $this->respond($basket);
    }

    public function addProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::query()->where('slug', $data['slug'])->active()->first();

        if ($product === null) {
            // A draft or deleted product must not enter a basket: the quote
            // would name something nobody can supply.
            throw new NotFoundHttpException("No product with slug {$data['slug']}");
        }

        $basket = $this->session->resolveOrCreate($request);

        $this->service->addProduct($basket, $product, (int) ($data['qty'] ?? 1), $data['note'] ?? null);

        return $this->respond($basket->fresh());
    }

    public function addFreeText(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $basket = $this->session->resolveOrCreate($request);

        try {
            $this->service->addFreeText(
                $basket,
                $data['description'],
                (int) ($data['qty'] ?? 1),
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return $this->respond($basket->fresh());
    }

    public function updateItem(Request $request, string $item): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $basket = $this->requireBasket($request);
        $line = $this->requireItem($basket, $item);

        if (array_key_exists('qty', $data) && $data['qty'] !== null) {
            $this->service->setQuantity($line, (int) $data['qty']);
        }

        if (array_key_exists('note', $data)) {
            $this->service->setNote($line, $data['note']);
        }

        return $this->respond($basket->fresh());
    }

    public function removeItem(Request $request, string $item): JsonResponse
    {
        $basket = $this->requireBasket($request);

        $this->service->remove($this->requireItem($basket, $item));

        return $this->respond($basket->fresh());
    }

    public function clear(Request $request): JsonResponse
    {
        $basket = $this->requireBasket($request);

        $this->service->clear($basket);

        return $this->respond($basket->fresh());
    }

    /**
     * A line is addressed by its own id, but only within the caller's basket.
     *
     * Scoping the lookup to the basket is what stops one visitor editing
     * another's line by guessing an id - the ids are sequential, so this is
     * not a hypothetical.
     */
    private function requireItem(QuoteBasket $basket, string $item): QuoteBasketItem
    {
        $line = $basket->items()->whereKey($item)->first();

        if ($line === null) {
            throw new NotFoundHttpException('No such line in this basket.');
        }

        return $line;
    }

    private function requireBasket(Request $request): QuoteBasket
    {
        $basket = $this->session->resolve($request);

        if ($basket === null) {
            throw new NotFoundHttpException('No active basket.');
        }

        return $basket;
    }

    private function respond(?QuoteBasket $basket): JsonResponse
    {
        // Loaded explicitly: lazy loading is disabled application-wide, and
        // BasketResource reads each line's product to resolve its display name
        // and its quantity rules.
        $basket?->load(['items.product']);

        $response = response()->json([
            'data' => new BasketResource($basket),
        ]);

        $cookie = $this->session->pendingCookie();

        return $cookie === null ? $response : $response->withCookie($cookie);
    }

    private function error(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'BasketRejected',
                'message' => $message,
                'details' => [],
                'trace_id' => request()->attributes->get('trace_id'),
            ],
        ], 422);
    }
}
