<?php

namespace App\Http\Controllers\Api\V1;

use App\Dto\Payload\CaptureItemPayload;
use App\Exceptions\ItemPersistenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureItemRequest;
use App\Http\Requests\ListItemsRequest;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Capture a free-text idea into the Inbox.
     *
     * @throws ItemPersistenceException
     */
    public function store(CaptureItemRequest $request): JsonResponse
    {
        return response()->json(
            $this->items->capture(CaptureItemPayload::fromArray($request->validated())),
            Response::HTTP_CREATED,
        );
    }

    /**
     * List the items in one GTD bucket, newest first.
     */
    public function index(ListItemsRequest $request): JsonResponse
    {
        return response()->json(
            $this->items->listByBucket($request->bucket()),
            Response::HTTP_OK,
        );
    }
}
