<?php

namespace App\Http\Controllers\Api\V1;

use App\Dto\Payload\CompleteItemPayload;
use App\Exceptions\ItemActionNotAllowedException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemPersistenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteItemRequest;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CompleteController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Mark an item done, or un-mark it.
     *
     * @throws ItemActionNotAllowedException
     * @throws ItemNotFoundException
     * @throws ItemPersistenceException
     */
    public function store(CompleteItemRequest $request, int $itemId): JsonResponse
    {
        return response()->json(
            $this->items->setCompleted($itemId, CompleteItemPayload::fromArray($request->validated())),
            Response::HTTP_OK,
        );
    }
}
