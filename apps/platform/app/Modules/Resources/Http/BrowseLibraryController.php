<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\BrowseResourceLibrary;
use App\Modules\Resources\Domain\CategoryId;
use Illuminate\Http\JsonResponse;

final readonly class BrowseLibraryController
{
    public function __invoke(LibraryRequest $request, BrowseResourceLibrary $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        $category = $request->category();

        return response()->json($presenter->library($use($actors->for($request), $category === null ? null : CategoryId::fromString($category), $request->text())), 200);
    }
}
