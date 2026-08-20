# PGE AI Photo Tools

API-free WordPress photo utility plugin with a smart command interface.

## Shortcodes

- `[pge_ai]` — full photo tools + printable CV builder.
- `[pge_ai mode="photo"]` — photo tools only.
- `[pge_ai mode="passport"]` — photo tools only, suitable for a passport-photo page.
- `[pge_photo_tools]` — alias of `[pge_ai]`.

## What works without any paid API

- Multiple JPG/PNG/WebP uploads and sequential processing queue.
- Strong local photo enhancement (levels/contrast/brightness/sharpen; Imagick preferred).
- Conservative 2x/3x local resampling for visible HD-style output when requested.
- Exact resize and center-crop.
- Passport/ID presets, including `35x45` mm at 300 DPI and `2x2` inch at 300 DPI.
- CV/headshot square crop preset.
- White-background cleanup based on the image edge/corner background color.
- Local styles: studio, black-and-white, warm, cool, vintage.
- Brightness, contrast, blur, rotate and mirror commands.
- JPG/PNG/WebP conversion and output quality commands.
- Before vs Processed comparison plus an Applied Operations summary.
- Per-file downloads and optional ZIP batch download.
- Printable CV builder that stays in the browser and can be saved as PDF using Print > Save as PDF.
- Guest-access toggle, upload limits, dimension limits, rate limiting, signed result paths and automatic output cleanup.

## Example commands

```text
clear photo
clear photo hd 2x
passport 35x45 white background
passport 2x2 white background
cv photo white background studio
resize 600x600 webp quality 88
black and white clear photo
brighter more contrast
rotate 90
mirror
warm
cool
vintage
```

Bangla/Banglish keywords such as `ফটো ক্লিয়ার`, `পাসপোর্ট`, `সাদা ব্যাকগ্রাউন্ড`, `স্টুডিও`, `সাদাকালো`, `clear koro`, and `background sada` are recognized.

Unknown free-text commands do not silently return an almost-identical image; PGE falls back to strong local auto-enhance and shows a notice.

## Server requirements

- WordPress 6.0+
- PHP 7.4+
- GD or Imagick (Imagick recommended)
- ZipArchive is optional and only needed for batch ZIP download

No API key is used or requested by the plugin.

## Important limitation

This plugin does **local image processing**, not generative AI. It can sharpen, upscale/resample, resize, crop, recolor and apply photographic filters. It cannot truthfully reconstruct facial detail that does not exist in the source, replace clothing, synthesize a new face, or perform robust semantic background segmentation. The white-background tool is a best-effort edge-color cleanup and works best with a simple, fairly uniform original background.

## Admin settings

Go to **Settings > PGE Photo Tools** to configure batch count, upload size, source dimension limit, output quality, cleanup retention, guest access, and ZIP support.

## Installation

1. Upload the `pge-ai-photo-tools` folder to `/wp-content/plugins/` or install the ZIP from WordPress Admin.
2. Activate **PGE AI Photo Tools**.
3. Add `[pge_ai]` to a page.
4. Optional: review **Settings > PGE Photo Tools**.
