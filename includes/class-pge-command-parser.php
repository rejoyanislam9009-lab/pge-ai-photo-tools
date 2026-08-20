<?php
if (!defined('ABSPATH')) {
    exit;
}

class PGE_Command_Parser {
    public static function parse($command) {
        $raw = sanitize_text_field((string) $command);
        $text = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);

        $ops = array(
            'enhance'          => false,
            'upscale'          => 1,
            'passport'         => false,
            'cv_photo'         => false,
            'white_background' => false,
            'style'            => '',
            'width'            => 0,
            'height'           => 0,
            'format'           => '',
            'quality'          => 0,
            'crop'             => false,
            'brightness'       => 0,
            'contrast'         => 0,
            'blur'             => 0,
            'rotate'           => 0,
            'flip'             => '',
            'recognized'       => false,
            'fallback'         => false,
        );

        if (self::has_any($text, array(
            'clear', 'enhance', 'sharpen', 'improve', 'clean photo', 'photo clear', 'make clear', 'hd photo',
            'ক্লিয়ার', 'ক্লিয়ার', 'পরিষ্কার', 'শার্প', 'এইচডি',
            'clear koro', 'photo clear koro', 'valo koro', 'bhalo koro'
        ))) {
            $ops['enhance'] = true;
            $ops['recognized'] = true;
            $ops['upscale'] = 2;
        }

        if (preg_match('/(?:upscale|scale|enlarge|hd)\s*[:=]?\s*([23])\s*x\b/i', $raw, $m)) {
            $ops['upscale'] = min(3, max(2, absint($m[1])));
            $ops['enhance'] = true;
            $ops['recognized'] = true;
        } elseif (!$ops['passport'] && preg_match('/\b([23])x\s*(?:upscale|enlarge|hd)\b/i', $raw, $m)) {
            $ops['upscale'] = min(3, max(2, absint($m[1])));
            $ops['enhance'] = true;
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array('passport', 'id photo', 'visa photo', 'পাসপোর্ট', 'আইডি ফটো'))) {
            $ops['passport'] = true;
            $ops['enhance'] = true;
            $ops['white_background'] = true;
            $ops['upscale'] = 1;
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array(
            'cv photo', 'resume photo', 'professional photo', 'professional headshot', 'headshot',
            'সিভি ফটো', 'প্রফেশনাল ফটো', 'cv pic', 'resume pic'
        ))) {
            $ops['cv_photo'] = true;
            $ops['enhance'] = true;
            $ops['white_background'] = true;
            $ops['style'] = 'studio';
            $ops['upscale'] = 1;
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array(
            'white background', 'background white', 'remove background', 'background remove', 'bg remove', 'white bg',
            'সাদা ব্যাকগ্রাউন্ড', 'ব্যাকগ্রাউন্ড সাদা', 'ব্যাকগ্রাউন্ড রিমুভ', 'background sada', 'bg sada'
        ))) {
            $ops['white_background'] = true;
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array('black and white', 'black & white', 'grayscale', 'greyscale', 'monochrome', 'bw', 'সাদা কালো', 'সাদাকালো'))) {
            $ops['style'] = 'bw';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('vintage', 'retro', 'sepia', 'পুরনো স্টাইল'))) {
            $ops['style'] = 'vintage';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('warm', 'উষ্ণ', 'warm tone'))) {
            $ops['style'] = 'warm';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('cool', 'কুল', 'cool tone'))) {
            $ops['style'] = 'cool';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('studio', 'স্টুডিও', 'professional look'))) {
            $ops['style'] = 'studio';
            $ops['enhance'] = true;
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array('brighten', 'brighter', 'bright photo', 'উজ্জ্বল', 'আলো বাড়াও', 'আলো বাড়াও'))) {
            $ops['brightness'] = 10;
            $ops['recognized'] = true;
        }
        if (self::has_any($text, array('darken', 'darker', 'কম উজ্জ্বল', 'আলো কমাও'))) {
            $ops['brightness'] = -10;
            $ops['recognized'] = true;
        }
        if (self::has_any($text, array('more contrast', 'increase contrast', 'contrast+', 'কনট্রাস্ট বাড়াও', 'কনট্রাস্ট বাড়াও'))) {
            $ops['contrast'] = 12;
            $ops['recognized'] = true;
        }
        if (self::has_any($text, array('less contrast', 'reduce contrast', 'contrast-', 'কনট্রাস্ট কমাও'))) {
            $ops['contrast'] = -10;
            $ops['recognized'] = true;
        }
        if (self::has_any($text, array('blur', 'soften', 'ব্লার', 'সফট'))) {
            $ops['blur'] = 2;
            $ops['recognized'] = true;
        }

        if (preg_match('/(?:rotate|ঘোরাও)\s*(90|180|270)/iu', $raw, $m)) {
            $ops['rotate'] = absint($m[1]);
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('rotate left', 'turn left', 'বামে ঘোরাও'))) {
            $ops['rotate'] = 270;
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('rotate right', 'turn right', 'ডানে ঘোরাও'))) {
            $ops['rotate'] = 90;
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array('flip horizontal', 'mirror', 'মিরর'))) {
            $ops['flip'] = 'horizontal';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('flip vertical'))) {
            $ops['flip'] = 'vertical';
            $ops['recognized'] = true;
        }

        if (preg_match('/(?:resize|size|crop|passport|photo|ছবি|সাইজ)?\s*(\d{1,4})\s*[x×]\s*(\d{1,4})/iu', $raw, $m)) {
            $a = absint($m[1]);
            $b = absint($m[2]);

            if ($ops['passport'] && $a <= 100 && $b <= 100) {
                list($a, $b) = self::physical_to_pixels($a, $b);
            }

            $ops['width'] = $a;
            $ops['height'] = $b;
            $ops['crop'] = true;
            $ops['recognized'] = true;
        }

        if ($ops['passport'] && (!$ops['width'] || !$ops['height'])) {
            $ops['width'] = 413;
            $ops['height'] = 531;
            $ops['crop'] = true;
        }

        if ($ops['cv_photo'] && (!$ops['width'] || !$ops['height'])) {
            $ops['width'] = 600;
            $ops['height'] = 600;
            $ops['crop'] = true;
        }

        if (preg_match('/\b(?:quality|q)\s*[:=]?\s*(\d{2,3})\b/i', $raw, $m)) {
            $ops['quality'] = min(100, max(40, absint($m[1])));
            $ops['recognized'] = true;
        }

        if (self::has_any($text, array('webp'))) {
            $ops['format'] = 'webp';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('png'))) {
            $ops['format'] = 'png';
            $ops['recognized'] = true;
        } elseif (self::has_any($text, array('jpg', 'jpeg'))) {
            $ops['format'] = 'jpg';
            $ops['recognized'] = true;
        }

        if (!$ops['recognized']) {
            $ops['enhance'] = true;
            $ops['upscale'] = 2;
            $ops['fallback'] = true;
        }

        return apply_filters('pge_ai_parsed_command', $ops, $raw);
    }

    public static function describe($ops) {
        $labels = array();
        if (!empty($ops['enhance'])) { $labels[] = __('strong local enhance', 'pge-ai-photo-tools'); }
        if (!empty($ops['upscale']) && absint($ops['upscale']) > 1 && empty($ops['width'])) { $labels[] = sprintf(__('%dx upscale', 'pge-ai-photo-tools'), absint($ops['upscale'])); }
        if (!empty($ops['passport'])) { $labels[] = __('passport preset', 'pge-ai-photo-tools'); }
        if (!empty($ops['cv_photo'])) { $labels[] = __('CV headshot preset', 'pge-ai-photo-tools'); }
        if (!empty($ops['white_background'])) { $labels[] = __('simple-background whitening', 'pge-ai-photo-tools'); }
        if (!empty($ops['style'])) { $labels[] = sprintf(__('style: %s', 'pge-ai-photo-tools'), sanitize_key($ops['style'])); }
        if (!empty($ops['width']) && !empty($ops['height'])) { $labels[] = sprintf(__('%dx%d crop', 'pge-ai-photo-tools'), absint($ops['width']), absint($ops['height'])); }
        if (!empty($ops['brightness'])) { $labels[] = __('brightness adjustment', 'pge-ai-photo-tools'); }
        if (!empty($ops['contrast'])) { $labels[] = __('contrast adjustment', 'pge-ai-photo-tools'); }
        if (!empty($ops['blur'])) { $labels[] = __('soft blur', 'pge-ai-photo-tools'); }
        if (!empty($ops['rotate'])) { $labels[] = sprintf(__('rotate %d°', 'pge-ai-photo-tools'), absint($ops['rotate'])); }
        if (!empty($ops['flip'])) { $labels[] = sprintf(__('flip %s', 'pge-ai-photo-tools'), sanitize_key($ops['flip'])); }
        if (!empty($ops['format'])) { $labels[] = sprintf(__('convert to %s', 'pge-ai-photo-tools'), strtoupper(sanitize_key($ops['format']))); }
        if (!empty($ops['fallback'])) { $labels[] = __('auto-enhance fallback', 'pge-ai-photo-tools'); }
        return implode(', ', $labels);
    }

    private static function has_any($haystack, $needles) {
        foreach ($needles as $needle) {
            $needle = function_exists('mb_strtolower') ? mb_strtolower($needle, 'UTF-8') : strtolower($needle);
            if (false !== strpos($haystack, $needle)) { return true; }
        }
        return false;
    }

    private static function physical_to_pixels($width, $height) {
        if (2 === $width && 2 === $height) { return array(600, 600); }
        $dpi = 300;
        $px_w = (int) round(($width / 25.4) * $dpi);
        $px_h = (int) round(($height / 25.4) * $dpi);
        return array(max(50, $px_w), max(50, $px_h));
    }
}
