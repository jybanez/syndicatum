# Syndicatum Icon System v1.0

Export date: 2026-09-07<br>
Color space: sRGB<br>
Designer/source application: clean SVG vector reconstruction from the approved Syndicatum presentation-board artwork; editable source SVG included.

## Approved colors

- Cyan: `#22EED6`
- Blue: `#2563EB`

These values are used exactly in all full-color SVG and PNG masters.

## Source of truth

This corrected v1.0 package was rebuilt from the approved six-shape visual shown on the final identity-system board, preserving its characteristic:
- central hexagonal core
- separated participant forms
- open internal channels
- top blue module
- upper-right cyan module
- right blue module
- lower-left cyan module
- left cyan module

No alternative procedural logo concept is used in this package.

## Standard master — 32 px and above

Use `svg/syndicatum-standard-color.svg` at 32 px and larger.

The standard master retains the approved silhouette, central hexagonal core, open channels,
clean flat fills, and rounded geometric corners. White and black monochrome variants are
provided alongside the color master.

## Micro master — 16–24 px

Use `svg/syndicatum-micro-color.svg` at 16, 20, and 24 px.

The micro master is **separately designed** and is not an automatic scale-down of the standard master.
It has:
- a larger central core
- wider internal gaps
- more aggressively simplified inward bends/junctions
- slightly smaller corner radii
- flat colors only

Minimum production size: **16 px**.

## Clear space

For standalone placement, keep clear space around the transparent mark equal to at least
half the width of the central hexagonal core. Do not place text or controls inside the mark's
open channels.

## Light and dark backgrounds

The transparent full-color master can be used on light or dark backgrounds when contrast is adequate.
The white monochrome version is for dark surfaces; the black monochrome version is for light surfaces.

## Monochrome

- `syndicatum-standard-white.svg`
- `syndicatum-standard-black.svg`
- `syndicatum-micro-white.svg`
- `syndicatum-micro-black.svg`
- `syndicatum-monochrome-currentcolor.svg`

The `currentColor` SVG uses CSS `currentColor` for automatic application recoloring.

## App tile

`syndicatum-app-tile-light.svg` and `syndicatum-app-tile-dark.svg` use the standard master
with additional safe-area padding on an opaque square tile.

## Maskable PWA icon

`syndicatum-maskable.svg` and the maskable PNG exports use a more conservative safe-area scale
and an opaque dark background. They are not simple transparent-icon reuse.

## Transparent PNG exports

Color, white, and black PNGs are supplied at:
16, 20, 24, 32, 48, 64, 128, 256, 512, and 1024 px.

- 16/20/24 px are rendered directly from the **micro vector master**
- 32 px and larger are rendered directly from the **standard vector master**
- no raster source is ever upscaled
- all transparent PNGs are exact square dimensions, RGBA, and contain an sRGB ICC profile

## Favicon

`web/favicon.ico` contains true independent:
- 16 px
- 32 px
- 48 px

layers.

The 16 px layer is rendered from the micro master. Chromium was used to render the final `.ico`
at native 16 px and 32 px for browser inspection: **not available**.

## Desktop

`desktop/syndicatum.ico` contains independent layers at:
16, 20, 24, 32, 48, 64, 128, and 256 px.

`desktop/syndicatum.icns` contains independently rendered PNG-backed chunks at:
16, 32, 64, 128, 256, 512, and 1024 px.

`desktop/macos-1024.png` uses the app-tile treatment and safe margins.

## Editable source

`source/syndicatum-icon-master.svg` contains separate editable vector groups:
- `standard-master`
- `micro-master`

No Illustrator file was used, so the clean source SVG is the authoritative editable source file.

## Production restrictions

Do not:
- substitute the previous procedural/reconstructed symbol
- add glow, filters, blur, bevels, shadows, or glass effects
- change the approved cyan/blue values
- collapse the open geometry into a turbine/spinner silhouette
