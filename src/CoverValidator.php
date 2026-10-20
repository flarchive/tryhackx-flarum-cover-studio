<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio;

use Flarum\Foundation\AbstractImageValidator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Validation\Factory;
use Intervention\Image\ImageManager;

/**
 * Validates cover image uploads: upload errors, client-declared mime type,
 * decodability (the image is actually parseable) and size limit.
 *
 * The REAL mime type (magic bytes) is verified again later in UploadBridge
 * via fof/upload's MimeTypeDetector — this validator is the first line only.
 */
class CoverValidator extends AbstractImageValidator
{
    public function __construct(
        Factory $validator,
        TranslatorInterface $translator,
        ImageManager $imageManager,
        protected SettingsRepositoryInterface $settings
    ) {
        parent::__construct($validator, $translator, $imageManager);
    }

    /**
     * Decompression-bomb ceiling: a few-hundred-KB PNG can decode to hundreds
     * of megapixels and exhaust memory. Checked from the image HEADER
     * (getimagesize — no decode) before the parent validator decodes the file.
     */
    public const MAX_PIXELS = 40_000_000; // ~40 MP, e.g. 8000x5000

    public function assertValid(array $attributes): void
    {
        $this->laravelValidator = $this->makeValidator($attributes);

        $file = $attributes[$this->filename];

        $this->assertFileRequired($file);
        $this->assertSaneDimensions($file);
        $this->assertFileMimes($file);
        $this->assertFileSize($file);
    }

    protected function assertSaneDimensions(\Psr\Http\Message\UploadedFileInterface $file): void
    {
        $uri = $file->getStream()->getMetadata('uri');
        $info = is_string($uri) ? @getimagesize($uri) : false;

        // Unparseable headers fall through to the parent's decode check, which
        // produces the proper "not an image" validation error.
        if ($info === false) {
            return;
        }

        if (($info[0] ?? 0) * ($info[1] ?? 0) > static::MAX_PIXELS) {
            $this->raise('image');
        }
    }

    /**
     * Note: intentionally PUBLIC — AbstractImageValidator declares this method
     * public, and PHP forbids reducing visibility in overrides. (This exact
     * mistake is what breaks sycho/flarum-profile-cover on Flarum 2.0 rc.)
     */
    public function getMaxSize(): int
    {
        $configured = (int) $this->settings->get('tryhackx-cover-studio.max_size', 2048);

        // Guard against nonsensical admin input; hard ceiling of 20 MB.
        return max(64, min($configured, 20480));
    }

    protected function getAllowedTypes(): array
    {
        // Deliberately excludes SVG (script execution surface) and BMP (huge,
        // poorly supported). GIF is allowed — animated covers are a feature.
        return ['jpeg', 'jpg', 'png', 'webp', 'gif'];
    }
}
