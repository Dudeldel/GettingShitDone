<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ItemPersistenceException;
use App\Http\Controllers\Controller;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Trash as a resource. Deleting it empties it.
 *
 * Its own controller, and no route parameter anywhere: the product's only irreversible
 * operation must not be addressable per item, or it becomes a generic delete verb reachable
 * from every bucket view.
 */
class TrashController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * Permanently discard every item in the Trash.
     *
     * @throws ItemPersistenceException
     */
    public function destroy(): JsonResponse
    {
        return response()->json(
            ['deleted' => $this->items->emptyTrash()],
            Response::HTTP_OK,
        );
    }
}
