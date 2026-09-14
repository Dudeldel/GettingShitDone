<?php

namespace App\Http\Controllers\Api\V1;

use App\Dto\Payload\ItemAttributesPayload;
use App\Exceptions\ItemActionNotAllowedException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemPersistenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateItemAttributesRequest;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Editing an item's attributes is a business operation on an existing item, so it gets its
 * own controller rather than another verb on ItemController (see app/CLAUDE.md block map).
 *
 * Its own NAMED verb rather than a generic PATCH, for the reason recorded twice already in
 * routes/api.php: a PATCH taking arbitrary fields lets a client choose where an item lands.
 * The distinction this slice adds is that "narrow" means narrow in WHICH COLUMNS it can
 * reach, not in how many it writes at once — ItemAttributesPayload has no field capable of
 * naming a bucket, so one verb covering all five attributes stays inside that discipline.
 */
class ItemAttributesController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Replace an item's due date, tags, context and Eisenhower flags.
     *
     * @throws ItemActionNotAllowedException
     * @throws ItemNotFoundException
     * @throws ItemPersistenceException
     */
    public function store(UpdateItemAttributesRequest $request, int $itemId): JsonResponse
    {
        return response()->json(
            $this->items->updateAttributes($itemId, ItemAttributesPayload::fromArray($request->validated())),
            Response::HTTP_OK,
        );
    }
}
