# Reverse geocoding provider setup

The address pin and delivery zone checks work independently of reverse-geocoding.
Address field suggestions are best-effort; customers can enter address fields manually
if the provider is unreachable.

## Configuration (Laravel .env)

```dotenv
GEOCODING_REVERSE_URL=https://nominatim.openstreetmap.org/reverse
# Optional: URL of your own hosted / contracted Nominatim-compatible HTTPS /reverse service
GEOCODING_FALLBACK_REVERSE_URL=
```

Both services must return Nominatim-compatible JSON with an `address` object,
such as `country_code`, `state`, `city`, `suburb`, `road`, `house_number`, `postcode`.
If the primary cannot connect, times out, returns an error or malformed data,
the fallback is attempted. Unconfigured fallback means the primary is the only provider.
No user PII is forwarded: only coordinates, language and HTTP User-Agent. The backend
rejects non-HTTPS endpoints. Keep endpoints under operator control; never accept URLs
from untrusted user input. Observe each provider's usage terms and privacy policy.

The cache is shared for one day per coordinate/language/provider configuration.
A shared cache (e.g. Redis) is recommended for multi-instance production to enforce
the rate limit across workers. Each endpoint has its own gate (at most one request
per two seconds). Repeated requests at an exhausted gate are not forwarded there.

**Important:** The public OpenStreetMap Nominatim endpoint is not guaranteed
to be reachable from every ISP and must not be treated as production infrastructure.
Use your own hosted service or an appropriately licensed provider for business traffic.
Do not set a commercial provider URL here unless it implements the required schema
and authentication; other geocoding APIs need an adapter.

After changing environment variables:

```sh
php artisan optimize:clear
php artisan test --filter=MapAddressTest
```

For a real connection failure, verify from the machine running Laravel:

```sh
curl -I https://nominatim.openstreetmap.org/
```

If both providers fail, API returns 503 without exposing their response contents,
and the client still permits manually entering address fields.

## Free map setup for ALL-5.75

The app uses Leaflet and OpenStreetMap map tiles to pick a pin. The Google Maps
link opens the same coordinates for optional visual inspection; a Google Maps
iframe is **not** used to read pin clicks (cross-origin browser restrictions).
The customer can always enter the Saudi address manually if the lookup fails.

For optional free-tier reverse address suggestions from Geoapify, sign up at
https://www.geoapify.com/ and create a free API key. Add to the **backend**
`.env` file (never use `VITE_` or commit the key):

```dotenv
GEOCODING_PROVIDER=geoapify
GEOAPIFY_API_KEY=PASTE_YOUR_PRIVATE_KEY_HERE
GEOCODING_REVERSE_URL=https://nominatim.openstreetmap.org/reverse
GEOCODING_FALLBACK_REVERSE_URL=
```

The provider is optional: omit both new variables to use the existing Nominatim
lookup and manual entry. When Geoapify is enabled, the server calls it first;
if it is down or over quota, the configured Nominatim-compatible service is
tried. **Your network currently cannot reach public Nominatim**, so choose a
reachable fallback service for resilience, or use manual address entry during
outages. No credit card is required to use the mapping UI itself. Provider
free-tier allowances and restrictions can change, so check the current terms.

Then run:

```sh
php artisan optimize:clear
php artisan test --filter=MapAddressTest
```

On the frontend:

```sh
npm run build
npm test
```

The map link is not reverse geocoding. Do not use Google Maps Embed iframe as a
replacement for receiving draggable pin coordinates or address fields.
