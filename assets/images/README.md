# Digitalogic Branding & Icon Assets

This directory contains the official Digitalogic brand icon assets used throughout the plugin and documentation.

## Files

### `icon.svg`
- **Type**: Full-color gradient SVG
- **Aspect Ratio**: 1:1 (square)
- **Size**: 552.72 × 552.72 viewBox
- **Usage**: 
  - README header
  - Documentation
  - Marketing materials
  - Repository social preview

### `icon-mono.svg`
- **Type**: Monochrome SVG (single color)
- **Color**: `#a7aaad` (WordPress admin gray)
- **Aspect Ratio**: 1:1 (square)
- **Size**: 20 × 20 (optimized for small displays)
- **Usage**:
  - WordPress admin menu icon (dashicon)
  - Plugin listings
  - Admin interface elements
  - Small UI components

## Technical Details

### Color Palette
The full-color icon uses a linear gradient:
- **Start**: `#00b3eb` (Cyan Blue)
- **End**: `#0168cd` (Deep Blue)

The gradient flows from bottom-left to top-right, creating a modern, professional look.

### Monochrome Version
The monochrome version uses WordPress's standard admin gray (`#a7aaad`) which automatically adapts to:
- Normal state: Gray
- Active/hover state: White (WordPress handles this)
- Focus state: Blue highlight (WordPress handles this)

## Integration

### WordPress Admin Menu
The icon is integrated into the WordPress admin menu via the `Digitalogic_Admin::get_menu_icon()` method, which returns a base64-encoded data URL of the monochrome SVG.

```php
// In includes/admin/class-admin.php
private function get_menu_icon() {
    $svg = '<svg>...</svg>'; // Monochrome icon
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
```

### README
The full-color icon is displayed in the README header:

```markdown
<div align="center">
  <img src="assets/images/icon.svg" alt="Digitalogic Logo" width="200" height="200">
</div>
```

## Design Notes

The icon represents Digitalogic's focus on:
- **Electronic circuits**: The interconnected paths symbolize circuit boards
- **Digital logic**: The geometric precision represents digital components
- **Connectivity**: The network-like structure shows integration capabilities
- **Plus symbol**: Central cross represents addition/extension functionality

## Customization

If you need to customize the icon:

1. **Color changes**: Edit the gradient stops in `icon.svg`
2. **Monochrome color**: Change the `fill="#a7aaad"` attribute in `icon-mono.svg`
3. **Size optimization**: Use SVGO or similar tools to further optimize file size
4. **Format conversion**: Use ImageMagick or similar to convert to PNG/JPG if needed

## File Size

- `icon.svg`: ~2.8 KB
- `icon-mono.svg`: ~2.5 KB

Both files are optimized for web use and load quickly.

## Credits

Icon design: Digitalogic Brand Team  
Implementation: atomicdeploy/digitalogic-wp
