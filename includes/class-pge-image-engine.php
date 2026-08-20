<?php
if (!defined('ABSPATH')) {
    exit;
}

class PGE_Image_Engine {
    public static function process($source, $destination_dir, $ops, $settings) {
        $info = @getimagesize($source);
        if (!$info || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            return new WP_Error('invalid_image', __('The uploaded file is not a valid image.', 'pge-ai-photo-tools'));
        }

        $max_dimension = max(1000, absint(isset($settings['max_dimension']) ? $settings['max_dimension'] : 6000));
        if ($info[0] > $max_dimension || $info[1] > $max_dimension) {
            return new WP_Error('image_too_large', sprintf(__('Image dimensions exceed the %d px safety limit.', 'pge-ai-photo-tools'), $max_dimension));
        }

        wp_mkdir_p($destination_dir);

        if (class_exists('Imagick')) {
            $result = self::process_imagick($source, $destination_dir, $ops, $settings, $max_dimension);
            if (!is_wp_error($result)) {
                return $result;
            }
        }

        if (function_exists('imagecreatefromjpeg')) {
            return self::process_gd($source, $destination_dir, $ops, $settings, $info['mime'], $max_dimension);
        }

        return new WP_Error('no_image_library', __('Neither Imagick nor GD is available on this server.', 'pge-ai-photo-tools'));
    }

    private static function process_imagick($source, $destination_dir, $ops, $settings, $max_dimension) {
        try {
            $image = new Imagick();
            $image->readImage($source);
            $image->setIteratorIndex(0);

            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }

            if (defined('Imagick::COLORSPACE_SRGB')) {
                try {
                    $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                } catch (Exception $e) {
                    // Keep the original colorspace if conversion is unsupported.
                }
            }

            if (!empty($ops['white_background'])) {
                self::imagick_whiten_background($image);
            }

            if (!empty($ops['enhance'])) {
                self::imagick_enhance($image);
            }

            self::imagick_adjustments($image, $ops);

            if (!empty($ops['style'])) {
                self::imagick_style($image, $ops['style']);
            }

            if (!empty($ops['rotate'])) {
                $image->setImageBackgroundColor(new ImagickPixel('white'));
                $image->rotateImage(new ImagickPixel('white'), (float) absint($ops['rotate']));
            }

            if (!empty($ops['flip'])) {
                if ('horizontal' === $ops['flip']) {
                    $image->flopImage();
                } elseif ('vertical' === $ops['flip']) {
                    $image->flipImage();
                }
            }

            if (!empty($ops['width']) && !empty($ops['height'])) {
                $w = max(32, min($max_dimension, absint($ops['width'])));
                $h = max(32, min($max_dimension, absint($ops['height'])));
                if (!empty($ops['crop'])) {
                    $image->cropThumbnailImage($w, $h);
                } else {
                    $image->thumbnailImage($w, $h, true, true);
                }
            } elseif (!empty($ops['upscale']) && absint($ops['upscale']) > 1) {
                self::imagick_upscale($image, absint($ops['upscale']), $max_dimension);
            }

            $format = self::resolve_format($ops, $source);
            $quality = self::resolve_quality($ops, $settings);
            $filename = self::build_filename($source, $format);
            $destination = trailingslashit($destination_dir) . $filename;

            if ('jpg' === $format) {
                $image->setImageBackgroundColor('white');
                if ($image->getImageAlphaChannel()) {
                    $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                }
                $image->setImageFormat('jpeg');
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            } elseif ('png' === $format) {
                $image->setImageFormat('png');
            } else {
                $image->setImageFormat('webp');
            }

            $image->setImageCompressionQuality($quality);
            $image->stripImage();
            $image->writeImage($destination);
            $out_width = $image->getImageWidth();
            $out_height = $image->getImageHeight();
            $image->clear();
            $image->destroy();

            return array(
                'path'   => $destination,
                'format' => $format,
                'width'  => $out_width,
                'height' => $out_height,
                'engine' => 'Imagick',
            );
        } catch (Exception $e) {
            return new WP_Error('imagick_failed', $e->getMessage());
        }
    }

    private static function imagick_enhance($image) {
        try {
            if (method_exists($image, 'despeckleImage')) {
                $image->despeckleImage();
            }
            if (method_exists($image, 'autoLevelImage')) {
                $image->autoLevelImage();
            } else {
                $image->normalizeImage();
            }
            $image->unsharpMaskImage(0, 1.15, 1.15, 0.025);
            $image->modulateImage(103, 108, 100);
            $image->contrastImage(true);
        } catch (Exception $e) {
            // Keep any successful earlier enhancement steps.
        }
    }

    private static function imagick_adjustments($image, $ops) {
        try {
            if (!empty($ops['brightness'])) {
                $brightness = max(70, min(130, 100 + (int) $ops['brightness']));
                $image->modulateImage($brightness, 100, 100);
            }
            if (!empty($ops['contrast'])) {
                $steps = max(1, min(4, (int) ceil(abs((int) $ops['contrast']) / 5)));
                $increase = ((int) $ops['contrast']) > 0;
                for ($i = 0; $i < $steps; $i++) {
                    $image->contrastImage($increase);
                }
            }
            if (!empty($ops['blur'])) {
                $image->gaussianBlurImage(0, min(4, max(0.5, (float) $ops['blur'])));
            }
        } catch (Exception $e) {
            // Best-effort adjustments.
        }
    }

    private static function imagick_upscale($image, $factor, $max_dimension) {
        $factor = max(1, min(3, absint($factor)));
        if ($factor < 2) {
            return;
        }
        $w = $image->getImageWidth();
        $h = $image->getImageHeight();
        $longest = max($w, $h);
        if ($longest <= 0 || $longest >= $max_dimension) {
            return;
        }
        $effective = min($factor, $max_dimension / $longest);
        $new_w = max(1, (int) round($w * $effective));
        $new_h = max(1, (int) round($h * $effective));
        if ($new_w === $w && $new_h === $h) {
            return;
        }
        $filter = defined('Imagick::FILTER_LANCZOS') ? Imagick::FILTER_LANCZOS : Imagick::FILTER_UNDEFINED;
        $image->resizeImage($new_w, $new_h, $filter, 1, true);
        $image->unsharpMaskImage(0, 0.65, 0.7, 0.02);
    }

    private static function imagick_whiten_background($image) {
        try {
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $points = array(
                array(0, 0),
                array(max(0, $width - 1), 0),
                array(0, max(0, $height - 1)),
                array(max(0, $width - 1), max(0, $height - 1)),
            );

            $quantum = method_exists('Imagick', 'getQuantum') ? Imagick::getQuantum() : 65535;
            $fuzz = $quantum * 0.10;
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

            foreach ($points as $point) {
                $pixel = $image->getImagePixelColor($point[0], $point[1]);
                if (method_exists($image, 'transparentPaintImage')) {
                    $image->transparentPaintImage($pixel, 0.0, $fuzz, false);
                }
            }

            $canvas = new Imagick();
            $canvas->newImage($width, $height, new ImagickPixel('white'));
            $canvas->setImageFormat('png');
            $canvas->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
            $image->clear();
            $image->readImageBlob($canvas->getImageBlob());
            $canvas->clear();
            $canvas->destroy();
        } catch (Exception $e) {
            // Simple uniform-background whitening is best-effort.
        }
    }

    private static function imagick_style($image, $style) {
        switch ($style) {
            case 'bw':
                $image->setImageType(Imagick::IMGTYPE_GRAYSCALE);
                $image->contrastImage(true);
                break;
            case 'warm':
                $image->modulateImage(104, 112, 100);
                $image->colorizeImage(new ImagickPixel('#ffb56b'), 0.10);
                break;
            case 'cool':
                $image->modulateImage(103, 105, 100);
                $image->colorizeImage(new ImagickPixel('#7db7ff'), 0.10);
                break;
            case 'vintage':
                $image->sepiaToneImage(76);
                $image->modulateImage(98, 86, 100);
                break;
            case 'studio':
                $image->modulateImage(106, 107, 100);
                $image->unsharpMaskImage(0, 0.9, 0.9, 0.025);
                $image->contrastImage(true);
                break;
        }
    }

    private static function process_gd($source, $destination_dir, $ops, $settings, $mime, $max_dimension) {
        $image = self::gd_load($source, $mime);
        if (!$image) {
            return new WP_Error('gd_load_failed', __('GD could not read this image format.', 'pge-ai-photo-tools'));
        }

        if (!empty($ops['white_background'])) {
            self::gd_whiten_background($image);
        }

        if (!empty($ops['enhance'])) {
            @imagefilter($image, IMG_FILTER_CONTRAST, -12);
            @imagefilter($image, IMG_FILTER_BRIGHTNESS, 4);
            if (defined('IMG_FILTER_SMOOTH')) {
                @imagefilter($image, IMG_FILTER_SMOOTH, 2);
            }
            if (function_exists('imageconvolution')) {
                $matrix = array(
                    array(0, -1, 0),
                    array(-1, 5, -1),
                    array(0, -1, 0),
                );
                @imageconvolution($image, $matrix, 1, 0);
            }
        }

        self::gd_adjustments($image, $ops);

        if (!empty($ops['style'])) {
            self::gd_style($image, $ops['style']);
        }

        if (!empty($ops['rotate'])) {
            $rotated = self::gd_rotate($image, absint($ops['rotate']));
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        if (!empty($ops['flip']) && function_exists('imageflip')) {
            if ('horizontal' === $ops['flip']) {
                @imageflip($image, IMG_FLIP_HORIZONTAL);
            } elseif ('vertical' === $ops['flip']) {
                @imageflip($image, IMG_FLIP_VERTICAL);
            }
        }

        if (!empty($ops['width']) && !empty($ops['height'])) {
            $resized = self::gd_resize_crop($image, min($max_dimension, absint($ops['width'])), min($max_dimension, absint($ops['height'])), !empty($ops['crop']));
            if ($resized) {
                imagedestroy($image);
                $image = $resized;
            }
        } elseif (!empty($ops['upscale']) && absint($ops['upscale']) > 1) {
            $resized = self::gd_upscale($image, absint($ops['upscale']), $max_dimension);
            if ($resized) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        $format = self::resolve_format($ops, $source);
        $quality = self::resolve_quality($ops, $settings);
        $filename = self::build_filename($source, $format);
        $destination = trailingslashit($destination_dir) . $filename;

        $saved = false;
        if ('png' === $format) {
            imagesavealpha($image, true);
            $compression = (int) round((100 - $quality) * 9 / 100);
            $saved = imagepng($image, $destination, min(9, max(0, $compression)));
        } elseif ('webp' === $format && function_exists('imagewebp')) {
            $saved = imagewebp($image, $destination, $quality);
        } else {
            if ('webp' === $format && !function_exists('imagewebp')) {
                $format = 'jpg';
                $filename = self::build_filename($source, $format);
                $destination = trailingslashit($destination_dir) . $filename;
            }
            $flattened = self::gd_flatten_white($image);
            if ($flattened !== $image) {
                imagedestroy($image);
                $image = $flattened;
            }
            $saved = imagejpeg($image, $destination, $quality);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        if (!$saved) {
            return new WP_Error('gd_save_failed', __('GD could not save the processed image.', 'pge-ai-photo-tools'));
        }

        return array(
            'path'   => $destination,
            'format' => $format,
            'width'  => $width,
            'height' => $height,
            'engine' => 'GD',
        );
    }

    private static function gd_adjustments(&$image, $ops) {
        if (!empty($ops['brightness'])) {
            @imagefilter($image, IMG_FILTER_BRIGHTNESS, max(-50, min(50, (int) $ops['brightness'])));
        }
        if (!empty($ops['contrast'])) {
            @imagefilter($image, IMG_FILTER_CONTRAST, max(-50, min(50, -(int) $ops['contrast'])));
        }
        if (!empty($ops['blur'])) {
            $passes = max(1, min(4, absint($ops['blur'])));
            for ($i = 0; $i < $passes; $i++) {
                @imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }
    }

    private static function gd_load($path, $mime) {
        switch ($mime) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($path);
            case 'image/png':
                return @imagecreatefrompng($path);
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
            default:
                return false;
        }
    }

    private static function gd_whiten_background(&$image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $pixels = $width * $height;
        if ($pixels > 12000000) {
            return;
        }

        $corners = array(
            imagecolorat($image, 0, 0),
            imagecolorat($image, max(0, $width - 1), 0),
            imagecolorat($image, 0, max(0, $height - 1)),
            imagecolorat($image, max(0, $width - 1), max(0, $height - 1)),
        );

        $avg = array('red' => 0, 'green' => 0, 'blue' => 0);
        foreach ($corners as $color) {
            $c = imagecolorsforindex($image, $color);
            $avg['red'] += $c['red'];
            $avg['green'] += $c['green'];
            $avg['blue'] += $c['blue'];
        }
        $avg['red'] /= 4;
        $avg['green'] /= 4;
        $avg['blue'] /= 4;
        $white = imagecolorallocate($image, 255, 255, 255);
        $threshold = 50;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $distance = sqrt(
                    pow($rgb['red'] - $avg['red'], 2) +
                    pow($rgb['green'] - $avg['green'], 2) +
                    pow($rgb['blue'] - $avg['blue'], 2)
                );
                if ($distance < $threshold) {
                    imagesetpixel($image, $x, $y, $white);
                }
            }
        }
    }

    private static function gd_style(&$image, $style) {
        switch ($style) {
            case 'bw':
                @imagefilter($image, IMG_FILTER_GRAYSCALE);
                @imagefilter($image, IMG_FILTER_CONTRAST, -10);
                break;
            case 'warm':
                @imagefilter($image, IMG_FILTER_COLORIZE, 16, 5, -8, 0);
                break;
            case 'cool':
                @imagefilter($image, IMG_FILTER_COLORIZE, -9, 2, 16, 0);
                break;
            case 'vintage':
                @imagefilter($image, IMG_FILTER_GRAYSCALE);
                @imagefilter($image, IMG_FILTER_COLORIZE, 42, 24, 7, 0);
                @imagefilter($image, IMG_FILTER_CONTRAST, 4);
                break;
            case 'studio':
                @imagefilter($image, IMG_FILTER_BRIGHTNESS, 7);
                @imagefilter($image, IMG_FILTER_CONTRAST, -9);
                if (function_exists('imageconvolution')) {
                    $matrix = array(array(0, -1, 0), array(-1, 5, -1), array(0, -1, 0));
                    @imageconvolution($image, $matrix, 1, 0);
                }
                break;
        }
    }

    private static function gd_rotate($image, $degrees) {
        $degrees = $degrees % 360;
        if (!in_array($degrees, array(90, 180, 270), true)) {
            return false;
        }
        $gd_degrees = 360 - $degrees;
        $white = imagecolorallocate($image, 255, 255, 255);
        return @imagerotate($image, $gd_degrees, $white);
    }

    private static function gd_upscale($source, $factor, $max_dimension) {
        $factor = max(1, min(3, absint($factor)));
        $src_w = imagesx($source);
        $src_h = imagesy($source);
        $longest = max($src_w, $src_h);
        if ($factor < 2 || $longest <= 0 || $longest >= $max_dimension) {
            return false;
        }
        $effective = min($factor, $max_dimension / $longest);
        $target_w = max(1, (int) round($src_w * $effective));
        $target_h = max(1, (int) round($src_h * $effective));
        if ($target_w === $src_w && $target_h === $src_h) {
            return false;
        }
        return self::gd_resize_crop($source, $target_w, $target_h, true);
    }

    private static function gd_resize_crop($source, $target_w, $target_h, $crop) {
        $target_w = max(32, $target_w);
        $target_h = max(32, $target_h);
        $src_w = imagesx($source);
        $src_h = imagesy($source);

        $dest = imagecreatetruecolor($target_w, $target_h);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
        imagefill($dest, 0, 0, $transparent);

        if ($crop) {
            $src_ratio = $src_w / $src_h;
            $target_ratio = $target_w / $target_h;
            if ($src_ratio > $target_ratio) {
                $crop_h = $src_h;
                $crop_w = (int) round($src_h * $target_ratio);
                $src_x = (int) round(($src_w - $crop_w) / 2);
                $src_y = 0;
            } else {
                $crop_w = $src_w;
                $crop_h = (int) round($src_w / $target_ratio);
                $src_x = 0;
                $src_y = (int) round(($src_h - $crop_h) / 2);
            }
            imagecopyresampled($dest, $source, 0, 0, $src_x, $src_y, $target_w, $target_h, $crop_w, $crop_h);
        } else {
            $ratio = min($target_w / $src_w, $target_h / $src_h);
            $new_w = (int) round($src_w * $ratio);
            $new_h = (int) round($src_h * $ratio);
            $dst_x = (int) round(($target_w - $new_w) / 2);
            $dst_y = (int) round(($target_h - $new_h) / 2);
            imagecopyresampled($dest, $source, $dst_x, $dst_y, 0, 0, $new_w, $new_h, $src_w, $src_h);
        }

        return $dest;
    }

    private static function gd_flatten_white($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $flat = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
        return $flat;
    }

    private static function resolve_format($ops, $source) {
        if (!empty($ops['format']) && in_array($ops['format'], array('jpg', 'png', 'webp'), true)) {
            return $ops['format'];
        }
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ('jpeg' === $ext) {
            return 'jpg';
        }
        return in_array($ext, array('jpg', 'png', 'webp'), true) ? $ext : 'jpg';
    }

    private static function resolve_quality($ops, $settings) {
        if (!empty($ops['quality'])) {
            return min(100, max(40, absint($ops['quality'])));
        }
        return min(100, max(40, absint(isset($settings['output_quality']) ? $settings['output_quality'] : 90)));
    }

    private static function build_filename($source, $format) {
        $base = sanitize_file_name(pathinfo($source, PATHINFO_FILENAME));
        $base = $base ? $base : 'photo';
        return $base . '-pge-' . wp_generate_password(8, false, false) . '.' . $format;
    }
}
