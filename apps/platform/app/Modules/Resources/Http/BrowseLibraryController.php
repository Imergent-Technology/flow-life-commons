<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\BrowseLibrary;
use Illuminate\Http\JsonResponse;

final readonly class BrowseLibraryController
{
    public function __invoke(LibraryRequest $request, BrowseLibrary $use, RequestActor $actors, ViewerPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->library($use($actors->for($request), $request->filter())), 200);
    }
}
