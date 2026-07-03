<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Support;

use Flarum\Foundation\ValidationException;

/**
 * Focal-point value handling. A focal point is a pair of percentages (0–100)
 * describing which part of the image should stay centered when the image is
 * cropped or displayed with `background-size: cover`.
 */
class Focus
{
    public const DEFAULT = 50.0;

    public const ZOOM_DEFAULT = 1.0;
    // Below 1 = deliberate zoom-out: the image is pulled back past the cover
    // fit and the exposed bands are filled with a blurred copy of the image.
    public const ZOOM_MIN = 0.5;
    public const ZOOM_MAX = 4.0;

    /**
     * Clamp an already-numeric value into the valid 0–100 range,
     * rounded to two decimals (matches the DECIMAL(5,2) column).
     */
    public static function clamp(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }

    /**
     * Parse an untrusted request value into a valid focus percentage.
     *
     * @param mixed $value   raw request input
     * @param float $default returned when the value is absent (null)
     *
     * @throws ValidationException when the value is present but not numeric
     */
    public static function parse(mixed $value, float $default = self::DEFAULT): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_numeric($value)) {
            // resolve() rather than injection: this is a static helper used from
            // several services, and the translator is only needed on this
            // failure path.
            $translator = resolve(\Symfony\Contracts\Translation\TranslatorInterface::class);

            throw new ValidationException(['focus' => $translator->trans('tryhackx-cover-studio.api.invalid_focus')]);
        }

        return self::clamp((float) $value);
    }

    /**
     * Parse an untrusted request value into a valid zoom factor (0.50–4.00,
     * where 1 means "exactly the cover fit" / "largest possible avatar crop",
     * and values below 1 pull back with a blurred fill).
     */
    public static function parseZoom(mixed $value, float $default = self::ZOOM_DEFAULT): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_numeric($value)) {
            $translator = resolve(\Symfony\Contracts\Translation\TranslatorInterface::class);

            throw new ValidationException(['zoom' => $translator->trans('tryhackx-cover-studio.api.invalid_zoom')]);
        }

        return round(max(self::ZOOM_MIN, min(self::ZOOM_MAX, (float) $value)), 2);
    }
}
