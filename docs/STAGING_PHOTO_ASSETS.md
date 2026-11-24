# Staging product image mockups (six reviewed examples)

This staging-only photo-catalog PR supports **six original AI-generated illustrative product renders** with neutral studio backgrounds (not photographs of purchasable products). They replace unsuitable scraped Wikimedia photographs during staging UI review only.

The package distributed alongside the PR is **ALL575-staging-product-images-six.zip**. Unpack its `public/images/staging-photo-products/` entries into a local checkout and commit these binary JPEGs plus `GENERATED_ASSETS.json` on this QA branch.

Files included:

| Path (relative to `public/images/staging-photo-products/`) | Meaning |
|---|---|
| shampoo-bottle.jpg | Unbranded green shampoo bottle studio render |
| toothbrush.jpg | Three unused toothbrushes |
| lipstick.jpg | Generic cosmetic lipstick |
| mascara.jpg | Generic mascara tube and wand |
| perfume-bottle.jpg | Unbranded decorative perfume bottle |
| cologne-bottle.jpg | Unbranded amber cologne bottle |
| GENERATED_ASSETS.json | Truthful source metadata, marked `illustrative_only: true` |

Run `ALL575_PHOTO_PREVIEW_ONLY=1 php artisan db:seed --class=StagingPhotoCatalogSeeder --force` only in `APP_ENV=staging` / `market575_staging_clone`. It loads these six local assets without calling Wikimedia for them, and attempts the remaining 12 photographs from Commons. It writes attribution and missing-photo previews, **not DB records**.

Do not claim generated mockups are real product photographs or available seller inventory. Do not approve/publish demonstration products as ready-to-buy without verifying actual items, seller authorization, stock and rights. Never run on production. The seeder requires individual image review and a separate explicit publication gate.

These images cannot be reconstructed from this text file alone; the actual JPEG files must be committed to GitHub separately.
