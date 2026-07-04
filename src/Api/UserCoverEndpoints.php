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

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\User\User;
use Illuminate\Support\Arr;
use TryHackX\CoverStudio\AvatarFocusService;
use TryHackX\CoverStudio\CoverService;

/**
 * Custom endpoints on the users resource. All authorization happens inside
 * the services (assertRegistered + policy checks); $context->model is already
 * visibility-scoped by UserResource::scope().
 *
 * Routes are namespaced under /cover-studio so they can never collide with
 * sycho/flarum-profile-cover's /users/{id}/cover while both are installed.
 *
 * IMPORTANT: no constructor dependencies here. Endpoint classes are
 * instantiated while API routes are populated — i.e. DURING the resolution of
 * the UrlGenerator singleton. The services below transitively require
 * fof/upload's FileRepository (which itself needs the UrlGenerator), so
 * injecting them in the constructor creates a circular container resolution
 * that crashes boot. They are resolved lazily inside the actions instead —
 * per request, long after boot has completed.
 */
class UserCoverEndpoints
{
    protected function covers(): CoverService
    {
        return resolve(CoverService::class);
    }

    protected function avatarFocus(): AvatarFocusService
    {
        return resolve(AvatarFocusService::class);
    }

    public function __invoke(): array
    {
        return [
            // Set a cover: multipart upload ("cover" file field) or JSON body
            // with "fileId" to reuse an existing media-manager file.
            Endpoint\Endpoint::make('coverStudio.cover.set')
                ->route('POST', '/{id}/cover-studio')
                ->action(function (Context $context) {
                    /** @var User $user */
                    $user = $context->model;
                    $actor = $context->getActor();
                    $body = (array) $context->request->getParsedBody();

                    $file = Arr::get($context->request->getUploadedFiles(), 'cover');

                    if ($file !== null) {
                        return $this->covers()->uploadAndSet(
                            $actor,
                            $user,
                            $file,
                            Arr::get($body, 'focusX'),
                            Arr::get($body, 'focusY'),
                            Arr::get($body, 'zoom')
                        );
                    }

                    return $this->covers()->attachExisting(
                        $actor,
                        $user,
                        Arr::get($body, 'fileId'),
                        Arr::get($body, 'focusX'),
                        Arr::get($body, 'focusY'),
                        Arr::get($body, 'zoom')
                    );
                }),

            // Re-position the existing cover (no re-upload).
            Endpoint\Endpoint::make('coverStudio.cover.focus')
                ->route('PATCH', '/{id}/cover-studio')
                ->action(function (Context $context) {
                    $body = (array) $context->request->getParsedBody();

                    return $this->covers()->setFocus(
                        $context->getActor(),
                        $context->model,
                        Arr::get($body, 'focusX'),
                        Arr::get($body, 'focusY'),
                        Arr::get($body, 'zoom')
                    );
                }),

            // Detach the cover (the file stays in the media manager).
            Endpoint\Endpoint::make('coverStudio.cover.remove')
                ->route('DELETE', '/{id}/cover-studio')
                ->action(function (Context $context) {
                    return $this->covers()->remove($context->getActor(), $context->model);
                }),

            // Set the avatar with focal-point support: multipart upload
            // ("avatar" file field) or JSON body with "fileId" to reuse an
            // existing media-manager image as the source/original.
            Endpoint\Endpoint::make('coverStudio.avatar.set')
                ->route('POST', '/{id}/cover-studio/avatar')
                ->action(function (Context $context) {
                    $body = (array) $context->request->getParsedBody();

                    $file = Arr::get($context->request->getUploadedFiles(), 'avatar');

                    if ($file !== null) {
                        return $this->avatarFocus()->uploadAndSet(
                            $context->getActor(),
                            $context->model,
                            $file,
                            Arr::get($body, 'focusX'),
                            Arr::get($body, 'focusY'),
                            Arr::get($body, 'zoom')
                        );
                    }

                    return $this->avatarFocus()->attachExisting(
                        $context->getActor(),
                        $context->model,
                        Arr::get($body, 'fileId'),
                        Arr::get($body, 'focusX'),
                        Arr::get($body, 'focusY'),
                        Arr::get($body, 'zoom')
                    );
                }),

            // Move the avatar focal point — server re-crops from the original.
            Endpoint\Endpoint::make('coverStudio.avatar.focus')
                ->route('PATCH', '/{id}/cover-studio/avatar')
                ->action(function (Context $context) {
                    $body = (array) $context->request->getParsedBody();

                    return $this->avatarFocus()->setFocus(
                        $context->getActor(),
                        $context->model,
                        Arr::get($body, 'focusX'),
                        Arr::get($body, 'focusY'),
                        Arr::get($body, 'zoom')
                    );
                }),
        ];
    }
}
