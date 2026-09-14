<?php

namespace App\Http\Controllers\Api\V1;

use App\Dto\Payload\RefileItemPayload;
use App\Exceptions\ItemActionNotAllowedException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemPersistenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RefileItemRequest;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-filing is a business operation that changes an item's state, so it gets its own
 * controller rather than another verb on ItemController (see app/CLAUDE.md block map) — and
 * its own controller rather than a mode of ClarifyController, because clarify must stay
 * Inbox-only.
 */
class RefileController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Move an already-clarified item into a different destination bucket.
     *
     * @throws ItemActionNotAllowedException
     * @throws ItemNotFoundException
     * @throws ItemPersistenceException
     */
    public function store(RefileItemRequest $request, int $itemId): JsonResponse
    {
        return response()->json(
            $this->items->refile($itemId, RefileItemPayload::fromArray($request->validated())),
            Response::HTTP_OK,
        );
    }
}
