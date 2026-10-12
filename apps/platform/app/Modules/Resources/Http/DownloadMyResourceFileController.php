<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\DownloadDeliveredFile;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class DownloadMyResourceFileController
{
    public function __invoke(DownloadFileRequest $request, string $pack, string $card, DownloadDeliveredFile $use, RequestActor $actors): StreamedResponse
    {
        return FileResponse::for($use($actors->for($request), PackId::fromString($pack), CardId::fromString($card)), $request->wantsInline());
    }
}
