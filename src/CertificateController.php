<?php

declare(strict_types=1);

namespace Preview;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Typography\FontFactory;

final class CertificateController
{
    /**
     * Resolve the certificate config array for a given type.
     *
     * @return array<string, mixed>
     */
    private static function getConfigForType(string $type): array
    {
        switch ($type) {
            case 'audit_v4':
                /** @var array $config */
                $config = config('certificate.audit_v4');
                return $config;
            case 'kyc_v4':
                /** @var array $config */
                $config = config('certificate.kyc_v4');
                return $config;
            default:
                throw new \InvalidArgumentException('Invalid certificate type');
        }
    }

    /**
     * Apply config-driven background/badge selections based on the project payload.
     * This keeps entrypoints thin and makes selection rules declarative in config/certificate.php.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function applyConfigSelections(string $type, array $project, array $data): array
    {
        $config = self::getConfigForType($type);

        if (is_array($config['background_select'] ?? null)) {
            $selectedBackground = self::selectBackgroundFromConfig($config, $project);
            if ($selectedBackground !== '') {
                $data['background'] = $selectedBackground;
            }
        }

        if (is_array($config['badge_select'] ?? null)) {
            $selectedBadges = self::selectBadgesFromConfig($config, $project);
            if ($selectedBadges) {
                $existing = is_array($data['badges'] ?? null) ? $data['badges'] : [];
                $data['badges'] = array_merge($existing, $selectedBadges);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $project
     */
    private static function selectBackgroundFromConfig(array $config, array $project): string
    {
        $default = (string)($config['background'] ?? '');
        $selector = $config['background_select'] ?? null;
        if (!is_array($selector)) {
            return $default;
        }

        $background = (string)($selector['default'] ?? $default);
        $rules = $selector['rules'] ?? null;
        if (!is_array($rules)) {
            return $background;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $conditions = $rule['if'] ?? null;
            if (!is_array($conditions)) {
                continue;
            }
            if (!self::conditionsMatch($conditions, $project)) {
                continue;
            }

            $candidate = (string)($rule['background'] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $background;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $project
     * @return array<string, string>
     */
    private static function selectBadgesFromConfig(array $config, array $project): array
    {
        $selector = $config['badge_select'] ?? null;
        if (!is_array($selector)) {
            return [];
        }

        $result = [];

        // Compute tier first so status can depend on it.
        $tierPath = null;
        if (isset($selector['tier']) && is_array($selector['tier'])) {
            $tierPath = self::selectBadgePath($selector['tier'], $project);
            if (is_string($tierPath) && $tierPath !== '' && self::badgeFileExists($tierPath)) {
                $result['tier'] = $tierPath;
            } else {
                $tierPath = null;
            }
        }

        // Audit contract badge, if configured.
        if (isset($selector['contract']) && is_array($selector['contract'])) {
            $contractPath = self::selectBadgePath($selector['contract'], $project);
            if (is_string($contractPath) && $contractPath !== '' && self::badgeFileExists($contractPath)) {
                $result['contract'] = $contractPath;
            }
        }

        // Partner/status badge: right when tier exists; centered when tier missing.
        if (isset($selector['status']) && is_array($selector['status'])) {
            $statusCfg = $selector['status'];
            $statusPath = self::selectBadgePath($statusCfg, $project, $tierPath !== null);
            if (is_string($statusPath) && $statusPath !== '' && self::badgeFileExists($statusPath)) {
                $result[$tierPath !== null ? 'status' : 'status_center'] = $statusPath;
            }
        }

        // Any other configured badge keys.
        foreach ($selector as $key => $badgeCfg) {
            if (!is_string($key) || $key === 'tier' || $key === 'status' || $key === 'contract') {
                continue;
            }
            if (!is_array($badgeCfg)) {
                continue;
            }
            $path = self::selectBadgePath($badgeCfg, $project);
            if (is_string($path) && $path !== '' && self::badgeFileExists($path)) {
                $result[$key] = $path;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $badgeCfg
     * @param array<string, mixed> $project
     */
    private static function selectBadgePath(array $badgeCfg, array $project, bool $hasTier = false): ?string
    {
        $haystack = null;
        if (!empty($badgeCfg['source_fields']) && is_array($badgeCfg['source_fields'])) {
            $haystack = self::buildHaystack($badgeCfg['source_fields'], $project);
        }

        $rules = $badgeCfg['rules'] ?? null;
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $conditions = $rule['if'] ?? null;
                if (!is_array($conditions)) {
                    continue;
                }
                if (!self::conditionsMatch($conditions, $project, $haystack)) {
                    continue;
                }
                $path = (string)($rule['path'] ?? '');
                if ($path !== '') {
                    return $path;
                }
            }
        }

        if (!$hasTier && isset($badgeCfg['default_when_no_tier'])) {
            $fallback = (string)$badgeCfg['default_when_no_tier'];
            if ($fallback !== '') {
                return $fallback;
            }
        }

        $default = $badgeCfg['default'] ?? null;
        if ($default === null) {
            return null;
        }
        $default = (string)$default;
        return $default !== '' ? $default : null;
    }

    /**
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $project
     */
    private static function conditionsMatch(array $conditions, array $project, ?string $haystack = null): bool
    {
        foreach ($conditions as $key => $expected) {
            if (!is_string($key)) {
                return false;
            }

            if ($key === 'haystack_contains') {
                $needle = strtolower((string)$expected);
                $hay = strtolower((string)$haystack);
                if ($needle === '' || $hay === '' || !str_contains($hay, $needle)) {
                    return false;
                }
                continue;
            }

            if (str_ends_with($key, '_contains')) {
                $field = substr($key, 0, -strlen('_contains'));
                $value = strtolower((string)($project[$field] ?? ''));
                $needle = strtolower((string)$expected);
                if ($needle === '' || $value === '' || !str_contains($value, $needle)) {
                    return false;
                }
                continue;
            }

            if (str_ends_with($key, '_not_contains')) {
                $field = substr($key, 0, -strlen('_not_contains'));
                $value = strtolower((string)($project[$field] ?? ''));
                $needle = strtolower((string)$expected);
                if ($needle === '') {
                    return false;
                }
                if ($value !== '' && str_contains($value, $needle)) {
                    return false;
                }
                continue;
            }

            if (str_ends_with($key, '_empty')) {
                $field = substr($key, 0, -strlen('_empty'));
                $isEmpty = trim((string)($project[$field] ?? '')) === '';
                if ((bool)$expected !== $isEmpty) {
                    return false;
                }
                continue;
            }

            // Unknown condition type => don't match.
            return false;
        }

        return true;
    }

    /**
     * @param list<mixed> $fields
     * @param array<string, mixed> $project
     */
    private static function buildHaystack(array $fields, array $project): string
    {
        $parts = [];
        foreach ($fields as $f) {
            if (!is_string($f) || $f === '') {
                continue;
            }
            $field = str_starts_with($f, 'project.') ? substr($f, strlen('project.')) : $f;
            $parts[] = (string)($project[$field] ?? '');
        }

        return strtolower(trim(implode(' ', $parts)));
    }

    private static function badgeFileExists(string $path): bool
    {
        if (!function_exists('storage_path')) {
            return true;
        }
        return is_file(storage_path($path));
    }

    /**
     * Standalone version of the Laravel controller's generateCertificate().
     * Uses helper functions storage_path() + config() provided by the preview bootstrap.
     */
    public static function generateCertificate(string $type, array $data): EncodedImageInterface
    {
        $config = self::getConfigForType($type);

        $background = (string)($config['background'] ?? '');

        $backgroundOverride = (string)($data['background'] ?? '');
        if ($backgroundOverride !== '') {
            $overridePath = storage_path($backgroundOverride);
            if (is_file($overridePath)) {
                $background = $backgroundOverride;
            }
        }

        if ($background === '') {
            throw new \RuntimeException('Missing config: certificate.*.background');
        }

        $date = (string)($data['date'] ?? '');

        // Create a new image resource
        $backgroundPath = storage_path($background);
        if (!is_file($backgroundPath)) {
            throw new \RuntimeException('Background not found: ' . $backgroundPath);
        }
        $image = ImageManager::gd()->read($backgroundPath);

        // Logo can be either a local path (relative to storage_path('app/...')) or an absolute path.
        $logoPath = (string)($data['logo_url'] ?? '');
        $logo = ImageManager::gd()->read(storage_path('app/' . $logoPath));

        $logoTrim = (bool)($config['logo']['trim'] ?? false);
        if ($logoTrim) {
            $alphaThreshold = (int)($config['logo']['trim_alpha'] ?? 126);
            $alphaThreshold = max(0, min(127, $alphaThreshold));
            $logo = self::trimTransparent($logo, $alphaThreshold);
        }

        // Add the title to the image
        $image->text((string)($data['title'] ?? ''), $config['title']['x'], $config['title']['y'], function (FontFactory $font) use ($config) {
            $fontFile = storage_path('/fonts/Montserrat/static/' . $config['title']['font']);
            $font->file($fontFile);
            $font->size($config['title']['size']);
            $font->color($config['title']['color']);
            $font->align($config['title']['align']);
            $font->valign($config['title']['valign']);
        });

        // Add the date to the image
        $image->text($date, $config['date']['x'], $config['date']['y'], function (FontFactory $font) use ($config) {
            $fontFile = storage_path('/fonts/Montserrat/static/' . $config['date']['font']);
            $font->file($fontFile);
            $font->size($config['date']['size']);
            $font->color($config['date']['color']);
            $font->align($config['date']['align']);
            $font->valign($config['date']['valign']);
        });

        // Add the logo to the image
        $logoWidth = isset($config['logo']['width']) ? (int)$config['logo']['width'] : null;
        $logoHeight = isset($config['logo']['height']) ? (int)$config['logo']['height'] : null;
        $logoAllowUpscale = (bool)($config['logo']['upscale'] ?? false);
        $logo = $logoAllowUpscale
            ? $logo->scale(width: $logoWidth, height: $logoHeight)
            : $logo->scaleDown(width: $logoWidth, height: $logoHeight);

        $logoScaleFactor = 1.0;
        if (isset($data['logo_scale']) && is_numeric($data['logo_scale'])) {
            $logoScaleFactor = (float)$data['logo_scale'];
        } elseif (isset($config['logo']['scale']) && is_numeric($config['logo']['scale'])) {
            $logoScaleFactor = (float)$config['logo']['scale'];
        }
        if (!is_finite($logoScaleFactor) || $logoScaleFactor <= 0) {
            $logoScaleFactor = 1.0;
        }
        $logoScaleFactor = max(0.1, min(3.0, $logoScaleFactor));
        if (abs($logoScaleFactor - 1.0) > 0.0001) {
            $newW = max(1, (int)round($logo->width() * $logoScaleFactor));
            $newH = max(1, (int)round($logo->height() * $logoScaleFactor));
            $logo = $logo->resize($newW, $newH);
        }

        $logoSharpen = (int)($config['logo']['sharpen'] ?? 0);
        $logoSharpen = max(0, min(100, $logoSharpen));

        $logoShape = strtolower((string)($config['logo']['shape'] ?? ''));
        $logoRound = (bool)($config['logo']['round'] ?? false);
        $logoCircle = $logoRound || $logoShape === 'circle' || $logoShape === 'round';
        if ($logoCircle) {
            $diameter = (int)($config['logo']['diameter'] ?? min($logo->width(), $logo->height()));
            if ($diameter > 0) {
                $logo = $logoAllowUpscale
                    ? $logo->cover($diameter, $diameter, 'center')
                    : $logo->coverDown($diameter, $diameter, 'center');

                // Sharpen after cover/resize but before alpha masking.
                if ($logoSharpen > 0) {
                    $logo = $logo->sharpen($logoSharpen);
                }

                $w = $logo->width();
                $h = $logo->height();
                $radius = min($w, $h) / 2;
                $cx = ($w - 1) / 2;
                $cy = ($h - 1) / 2;

                $src = $logo->core()->native();
                $dst = imagecreatetruecolor($w, $h);
                if ($dst === false) {
                    throw new \RuntimeException('Failed to create GD image for circular logo');
                }
                imagealphablending($dst, false);
                imagesavealpha($dst, true);

                $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefilledrectangle($dst, 0, 0, $w, $h, $transparent);
                imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $dx = $x - $cx;
                        $dy = $y - $cy;
                        if (($dx * $dx + $dy * $dy) > ($radius * $radius)) {
                            $c = imagecolorat($dst, $x, $y);
                            $r = ($c >> 16) & 0xFF;
                            $g = ($c >> 8) & 0xFF;
                            $b = $c & 0xFF;
                            $new = (127 << 24) | ($r << 16) | ($g << 8) | $b;
                            imagesetpixel($dst, $x, $y, $new);
                        }
                    }
                }

                ob_start();
                imagepng($dst);
                $png = (string)ob_get_clean();
                imagedestroy($dst);

                $logo = ImageManager::gd()->read($png);
            }
        } elseif ($logoSharpen > 0) {
            // Non-circular logos: sharpen after final resizing.
            $logo = $logo->sharpen($logoSharpen);
        }
        $image->place($logo, 'center', $config['logo']['x'], $config['logo']['y']);

        // Add the website to the image
        $image->text((string)($data['website'] ?? ''), $config['website']['x'], $config['website']['y'], function (FontFactory $font) use ($config) {
            $fontFile = storage_path('/fonts/Montserrat/static/' . $config['website']['font']);
            $font->file($fontFile);
            $font->size($config['website']['size']);
            $font->color($config['website']['color']);
            $font->align($config['website']['align']);
            $font->valign($config['website']['valign']);
        });

        // Optional badges (v4 cards)
        if (!empty($config['badges']) && is_array($config['badges']) && !empty($data['badges']) && is_array($data['badges'])) {
            foreach (array_values($config['badges']) as $i => $badgeCfg) {
                if (!is_array($badgeCfg)) {
                    continue;
                }

                $key = $badgeCfg['key'] ?? $i;
                $badgeRel = $data['badges'][$key] ?? null;
                if (!is_string($badgeRel) || $badgeRel === '') {
                    continue;
                }

                $badgePath = is_file($badgeRel) ? $badgeRel : storage_path($badgeRel);
                if (!is_file($badgePath)) {
                    continue;
                }

                $badgeImg = ImageManager::gd()->read($badgePath);
                $bw = isset($badgeCfg['width']) ? (int)$badgeCfg['width'] : null;
                $bh = isset($badgeCfg['height']) ? (int)$badgeCfg['height'] : null;
                $badgeImg = $badgeImg->scaleDown(width: $bw, height: $bh);

                $pos = (string)($badgeCfg['position'] ?? 'center');
                $ox = (int)($badgeCfg['x'] ?? 0);
                $oy = (int)($badgeCfg['y'] ?? 0);
                $opacity = (int)($badgeCfg['opacity'] ?? 100);
                $opacity = max(0, min(100, $opacity));

                $image->place($badgeImg, $pos, $ox, $oy, $opacity);
            }
        }

        // Add copyright to the image
        $copyrightText = (string)($data['copyright'] ?? ('© ' . date('Y') . ' SolidProof'));
        $copyrightUppercase = (bool)($config['copyright']['uppercase'] ?? true);
        $image->text($copyrightUppercase ? strtoupper($copyrightText) : $copyrightText, $config['copyright']['x'], $config['copyright']['y'], function (FontFactory $font) use ($config) {
            $fontFile = storage_path('/fonts/Montserrat/static/' . $config['copyright']['font']);
            $font->file($fontFile);
            $font->size($config['copyright']['size']);
            $font->color($config['copyright']['color']);
            $font->align($config['copyright']['align']);
            $font->valign($config['copyright']['valign']);
        });

        return $image->toJpeg(90);
    }

    private static function trimTransparent(\Intervention\Image\Interfaces\ImageInterface $image, int $alphaThreshold = 126): \Intervention\Image\Interfaces\ImageInterface
    {
        $native = $image->core()->native();
        if (!($native instanceof \GdImage) && !is_resource($native)) {
            return $image;
        }

        $w = imagesx($native);
        $h = imagesy($native);
        if ($w <= 1 || $h <= 1) {
            return $image;
        }

        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($native, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha <= $alphaThreshold) {
                    if ($x < $minX) $minX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($x > $maxX) $maxX = $x;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return $image;
        }

        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;
        if ($cropW <= 1 || $cropH <= 1) {
            return $image;
        }

        // Avoid churn if trimming doesn't change much.
        if ($cropW >= $w - 2 && $cropH >= $h - 2) {
            return $image;
        }

        $cropped = imagecrop($native, ['x' => $minX, 'y' => $minY, 'width' => $cropW, 'height' => $cropH]);
        if ($cropped === false) {
            return $image;
        }
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);

        ob_start();
        imagepng($cropped);
        $png = (string)ob_get_clean();
        imagedestroy($cropped);

        return ImageManager::gd()->read($png);
    }
}
