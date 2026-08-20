# Changelog

## 1.1.0
- Fixed the main issue where “clear/enhance” could look almost identical to the uploaded photo.
- Clear Photo now applies stronger local enhancement and a conservative 2x resample when no fixed output size is requested.
- Passport and CV presets now automatically apply enhancement and simple white-background treatment.
- Added Before vs Processed comparison in every result card.
- Added an Applied Operations summary and the active image engine (Imagick/GD).
- Added command support for brighter/darker, contrast, blur, rotate, mirror, 2x/3x upscale, and common Bangla/Banglish phrases.
- Unknown commands no longer fail silently; the plugin applies strong auto-enhance and shows a notice.
- Added cache-busting to generated result URLs and clearer AJAX error handling.

## 1.0.0
- Initial API-free WordPress photo tools release.
