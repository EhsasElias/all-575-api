# Staging photo products — approved visual direction (2026-11-24)

**Scope:** staging only (APP_ENV=staging and MySQL `market575_staging_clone`). Never deploy this mock catalogue to production.

This branch includes `database/seeders/StagingPhotoCatalogSeeder.php` and a provenance manifest:
`public/images/staging-photo-products/GENERATED_ASSETS.json`.

## Six replacement mockups

The following JPEG image assets must be placed **next to the manifest** before running preview; GitHub's text-only connector cannot commit the binary files automatically.

| Filename | Example listing | Source |
| --- | --- | --- |
| `shampoo-bottle.jpg` | Generic green shampoo bottle | AI-generated illustrative mockup |
| `toothbrush.jpg` | Three new colorful toothbrushes | AI-generated illustrative mockup |
| `lipstick.jpg` | Single lipstick with cap | AI-generated illustrative mockup |
| `mascara.jpg` | Black mascara tube and wand | AI-generated illustrative mockup |
| `perfume-bottle.jpg` | Decorative amber perfume bottle | AI-generated illustrative mockup |
| `cologne-bottle.jpg` | Square cologne bottle | AI-generated illustrative mockup |

The images are *generated studio illustrations*, **not real product photographs or evidence of inventory**. Keep `illustrative_only: true` on these entries; do not pretend a photographed item or brand is in stock. They are appropriate for a **clearly labelled staging demonstration**, not real seller inventory.

The other 12 products should use verified genuine commercial-use product photos when available, matched to the product, with the attribution data retained. No Wikimedia API rate-limit bypasses.

## Deployment / review

1. Extract the six JPEG files into `/var/www/market575/staging-api/public/images/staging-photo-products/` only. Keep the original images in a backup if replacing existing previews.
2. Copy the `GENERATED_ASSETS.json` manifest from this branch into that same directory.
3. Run `php -l database/seeders/StagingPhotoCatalogSeeder.php`.
4. Run **preview only**: `ALL575_PHOTO_PREVIEW_ONLY=1 php artisan db:seed --class=StagingPhotoCatalogSeeder --force` **once**, only if the Wikimedia rate limit has cleared.
5. Inspect every output image and source; review `ATTRIBUTION.json` and `MISSING.json`. Preview must not change the DB.
6. Do **not** set `ALL575_PHOTOS_REVIEWED=YES` or publish unless all 18 images have been individually reviewed, and a fresh recoverable backup and functional tests exist.

`StagingPhotoCatalogSeeder` archives old published listings to drafts rather than deleting them. It must never run against production.
