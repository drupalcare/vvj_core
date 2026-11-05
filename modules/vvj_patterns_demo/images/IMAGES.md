# VVJ Patterns demo images

Five pencil sketches made from the maintainer's own photographs (Alaa Haddad), released with this
module under GPL-2.0-or-later. Each one is 1600x1000 WebP.

Every Pattern puts white text over these images, so all five are dark on purpose. The brightest pixel
in each file is capped near sRGB 99 of 255, which keeps white text at about 6:1 contrast or better on
any part of the image (WCAG AA needs 4.5:1). Measured on the encoded WebP files.

How they were made (ffmpeg only): grayscale, a color-dodge pass for the pencil lines, a multiply pass
for the graphite shading, paper grain, then a tone curve that caps the brightness.

| File | Subject | Mean brightness | Brightest pixel |
|---|---|---|---|
| vvj-demo-1.webp | Tokyo skyline with Tokyo Tower | 62 of 255 | 101 (video range) |
| vvj-demo-2.webp | Elephant herd, acacia tree, Mount Kilimanjaro | 50 of 255 | 99 (video range) |
| vvj-demo-3.webp | Japanese garden: old trees, a pond, a temple roof | 34 of 255 | 99 (video range) |
| vvj-demo-4.webp | Tiger walking through dry grass | 52 of 255 | 101 (video range) |
| vvj-demo-5.webp | Chameleon on a leafy branch, close-up | 52 of 255 | 100 (video range) |

Source photographs: `solo-theme-drupal-developer-alaa-haddad-rectangle-` 75, 410, 413, 416 and 420.
