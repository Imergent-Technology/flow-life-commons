<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\BrowseDeliveredResources;
use Illuminate\Http\JsonResponse;

final readonly class BrowseMyResourcesController
{
    public function __invoke(MyResourcesRequest $request, BrowseDeliveredResources $use, RequestActor $actors, DeliveryPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->library($use($actors->for($request), $request->category(), $request->text())), 200);
    }
}
