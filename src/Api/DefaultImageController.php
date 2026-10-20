<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\CoverStudio\DefaultImageService;

/**
 * Admin-only endpoints for the forum-wide default cover / avatar image:
 *
 *   POST   /cover-studio/default/{kind}   set from a multipart "image" upload
 *                                         OR a "fileId" from shared media
 *   PATCH  /cover-studio/default/{kind}   re-position (focusX / focusY / zoom)
 *   DELETE /cover-studio/default/{kind}   clear
 *
 * {kind} is "cover" or "avatar". A single handler switches on the HTTP method
 * because the three actions share the same route and authorization. The
 * response carries the updated settings so the admin UI can refresh in place.
 *
 * The service is injected: route handlers are resolved per request (long after
 * boot), so its transitive fof/upload dependencies raise no circular-container
 * issues here — unlike the resource Endpoint classes in UserCoverEndpoints.
 */
class DefaultImageController implements RequestHandlerInterface
{
    public function __construct(
        protected DefaultImageService $service
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $kind = (string) Arr::get($request->getAttribute('routeParameters', []), 'kind', '');
        $body = (array) $request->getParsedBody();

        $values = match (strtoupper($request->getMethod())) {
            'DELETE' => $this->service->remove($actor, $kind),

            'PATCH' => $this->service->reposition(
                $actor,
                $kind,
                Arr::get($body, 'focusX'),
                Arr::get($body, 'focusY'),
                Arr::get($body, 'zoom')
            ),

            default => $this->service->set(
                $actor,
                $kind,
                Arr::get($request->getUploadedFiles(), 'image'),
                Arr::get($body, 'fileId'),
                Arr::get($body, 'focusX'),
                Arr::get($body, 'focusY'),
                Arr::get($body, 'zoom')
            ),
        };

        return new JsonResponse(['settings' => $values]);
    }
}
