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
