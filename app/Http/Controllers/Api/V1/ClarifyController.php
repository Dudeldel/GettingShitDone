<?php

namespace App\Http\Controllers\Api\V1;

use App\Dto\Payload\ClarifyItemPayload;
use App\Exceptions\InvalidClarificationException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemNotInInboxException;
use App\Exceptions\ItemPersistenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClarifyItemRequest;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clarify is a business operation that changes an item's state, so it gets its own
 * controller rather than another verb on ItemController (see app/CLAUDE.md block map).
 */
class ClarifyController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Route an Inbox item to its bucket by walking the GTD decision tree.
     *
     * @throws InvalidClarificationException
     * @throws ItemNotFoundException
     * @throws ItemNotInInboxException
     * @throws ItemPersistenceException
     */
    public function store(ClarifyItemRequest $request, int $itemId): JsonResponse
    {
        return response()->json(
            $this->items->clarify($itemId, ClarifyItemPayload::fromArray($request->validated())),
            Response::HTTP_OK,
        );
    }
}
