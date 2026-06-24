# laravel-redacted

Pull secrets from AWS SSM, Secrets Manager, Azure Key Vault, GCP Secret Manager, HashiCorp Vault, Infisical, and Doppler directly into your Laravel config files — using a single `redacted()` helper that works exactly like `env()`.

```php
// config/database.php
'password' => redacted('asm://prod/myapp/db#password', env('DB_PASSWORD')),
```

That's it. No middleware, no boot listeners, no service container gymnastics. Just drop it in your config file and move on with your life.

---

## Table of Contents

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [The URI format](#the-uri-format)
- [Drivers](#drivers)
  - [AWS SSM Parameter Store](#aws-ssm-parameter-store)
  - [AWS Secrets Manager](#aws-secrets-manager)
  - [Azure Key Vault](#azure-key-vault)
  - [GCP Secret Manager](#gcp-secret-manager)
  - [HashiCorp Vault](#hashicorp-vault)
  - [Infisical](#infisical)
  - [Doppler](#doppler)
  - [env](#env-driver)
  - [array](#array-driver)
- [Configuration reference](#configuration-reference)
- [The three-layer cache](#the-three-layer-cache)
- [Production: config:cache](#production-configcache)
- [Artisan commands](#artisan-commands)
- [Testing](#testing)
  - [Using fake()](#using-fake)
  - [TestCase setup](#testcase-setup)
- [Integration testing](#integration-testing)
  - [Running the suite](#running-the-suite)
  - [SSM integration tests](#ssm-integration-tests)
  - [Adding integration tests for other drivers](#adding-integration-tests-for-other-drivers)
- [Octane and long-running processes](#octane-and-long-running-processes)
- [Custom drivers](#custom-drivers)
- [Fallback values](#fallback-values)
- [The #fragment syntax](#the-fragment-syntax)

---

## How it works

The package hooks into Laravel's config loading phase. When your app boots, Laravel reads every file in `config/` and evaluates them. `redacted()` intercepts calls during this phase and resolves values from whichever secret store you've configured, then returns the plaintext string to sit in the config array just like any other value.

The clever bit is what happens when you run `php artisan config:cache`. Laravel executes your config files once, calls `redacted()` for each secret, gets back the actual values, and bakes the whole resolved config into `bootstrap/cache/config.php`. From that point on, until you regenerate the cache, your app reads secrets from a flat PHP file — zero network calls, zero latency, zero API credentials needed on the server. This is the recommended production setup and it's the reason this approach scales so cleanly.

For local development and environments where you can't or don't want to run `config:cache`, there's a three-layer caching system that keeps things snappy after the first resolution.

---

## Requirements

- PHP 8.2+
- Laravel 12+

---

## Installation

```bash
composer require yamut/laravel-redacted
```

The package auto-discovers itself via Laravel's package discovery. No need to add anything to `config/app.php`.

Publish the config file:

```bash
php artisan vendor:publish --tag=redacted-config
```

This drops `config/redacted.php` into your app. Open it and configure whichever drivers you plan to use.

---

## The URI format

Every `redacted()` call takes a URI as its first argument:

```
{scheme}://{path}[#{json_key}]
```

The scheme identifies the driver. The path is whatever the driver uses to locate the secret. The `#fragment` is optional and extracts a specific key from a JSON blob (more on that [below](#the-fragment-syntax)).

Here's what each driver's URI looks like in practice:

| Driver | Example URI |
|--------|-------------|
| AWS SSM | `ssm:///prod/myapp/db_password` |
| AWS Secrets Manager | `asm://prod/myapp/db` |
| ASM with JSON key | `asm://prod/myapp/db#password` |
| Azure Key Vault | `akv://my-vault/stripe-key` |
| GCP Secret Manager | `gcp://my-secret` |
| HashiCorp Vault | `vault://secret/myapp/stripe#secret_key` |
| Infisical | `infisical://DATABASE_URL` |
| Doppler | `doppler://DATABASE_URL` |
| Env var | `env://DB_HOST` |
| In-memory (tests) | `array://some-key` |

**The triple-slash thing**: SSM paths conventionally start with a `/` (e.g. `/prod/myapp/key`). Standard URIs treat `ssm://host/path` as host + path, so to represent a path that itself starts with `/`, you need `ssm:///prod/myapp/key` — three slashes total. The parser handles this correctly on PHP 8.2+.

---

## Drivers

### AWS SSM Parameter Store

Reads from [AWS Systems Manager Parameter Store](https://docs.aws.amazon.com/systems-manager/latest/userguide/systems-manager-parameter-store.html). SecureString parameters are always decrypted automatically.

**Config:**

```php
'ssm' => [
    'driver' => 'ssm',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'key'    => env('AWS_ACCESS_KEY_ID'),     // optional
    'secret' => env('AWS_SECRET_ACCESS_KEY'), // optional
],
```

Omit `key` and `secret` entirely to use IAM role credentials, ECS task roles, EC2 instance profiles — whatever's in your credential chain. The SDK will figure it out. Explicit credentials are only needed if you're not running on AWS infrastructure.

**Usage:**

```php
// SSM path /prod/myapp/db_password — triple-slash required for paths with leading /
redacted('ssm:///prod/myapp/db_password')

// Without leading slash (less common, but valid SSM paths exist):
redacted('ssm://prod/myapp/db_password')
```

**Prefetching:** The `redacted:cache` command fetches SSM parameters in batches of 10 (the SSM API limit for `GetParameters`). If a parameter doesn't exist, that slot comes back as `null` silently — SSM doesn't throw for missing names in batch mode, which is actually quite considerate of them.

---

### AWS Secrets Manager

Reads from [AWS Secrets Manager](https://docs.aws.amazon.com/secretsmanager/latest/userguide/intro.html). The killer feature here is JSON blob secrets — store a bunch of related credentials as a single JSON object and pull individual fields with the `#fragment` syntax.

**Config:**

```php
'asm' => [
    'driver' => 'asm',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
],
```

**Usage:**

```php
// A secret that contains a plain string value
redacted('asm://prod/myapp/stripe-key')

// A secret stored as JSON: {"host":"db.prod.internal","password":"hunter2","port":5432}
// Pull individual fields:
redacted('asm://prod/myapp/db#host')
redacted('asm://prod/myapp/db#password')
redacted('asm://prod/myapp/db#port')
```

Both `#host` and `#password` above resolve from the same single cached API call. See [The #fragment syntax](#the-fragment-syntax) for the full explanation.

**Binary secrets:** If your secret is stored as `SecretBinary` (base64-encoded binary), it's decoded to a plain string automatically.

**Prefetching:** Uses `BatchGetSecretValue` (added to the SDK in 2023) when available, falls back to sequential `GetSecretValue` calls. Your secrets need not fear the upgrade.

---

### Azure Key Vault

Reads from [Azure Key Vault](https://learn.microsoft.com/en-us/azure/key-vault/). One important thing to know upfront: AKV secret names can only contain alphanumerics and hyphens. No slashes, no underscores, no dots. If you're migrating from SSM where you had hierarchical paths, you'll need to flatten your naming scheme.

**Config (service principal):**

```php
'akv' => [
    'driver'              => 'akv',
    'vault_url'           => env('AZURE_KEY_VAULT_URL'),  // https://myvault.vault.azure.net
    'tenant_id'           => env('AZURE_TENANT_ID'),
    'client_id'           => env('AZURE_CLIENT_ID'),
    'client_secret'       => env('AZURE_CLIENT_SECRET'),
    'use_managed_identity'=> false,
],
```

**Config (managed identity):**

```php
'akv' => [
    'driver'              => 'akv',
    'vault_url'           => env('AZURE_KEY_VAULT_URL'),
    'use_managed_identity'=> true,
    // tenant_id, client_id, client_secret not needed
],
```

Managed identity uses the Azure IMDS endpoint at `169.254.169.254` — standard stuff if you're running on Azure VMs, App Service, or AKS.

**Usage:**

```php
// Secret named "stripe-secret-key" in your vault
redacted('akv://myvault/stripe-secret-key')

// The vault name in the URI is informational — the actual vault_url from config is used.
// You can put anything there, or just repeat your vault name for clarity.
```

**Important:** `vault_url` must use `https://`. The driver will not enforce this for you, so double-check your config.

---

### GCP Secret Manager

Reads from [Google Cloud Secret Manager](https://cloud.google.com/secret-manager). Clean API, sensible design, Google's best infrastructure product in years.

**Config:**

```php
'gcp' => [
    'driver'      => 'gcp',
    'project'     => env('GOOGLE_CLOUD_PROJECT'),
    'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'), // path to service account JSON
],
```

Omit `credentials` to use Application Default Credentials — works with `gcloud auth application-default login` locally, and with Workload Identity on GKE.

**Usage:**

```php
// Simple secret name — resolves to latest version automatically
redacted('gcp://stripe-secret-key')

// Full resource name if you need a specific version
redacted('gcp://projects/my-project/secrets/stripe-secret-key/versions/3')
```

---

### HashiCorp Vault

Reads from [HashiCorp Vault](https://www.vaultproject.io/) KV secrets engine. Supports both KV v1 and v2, and both token auth and AppRole.

**Config (token auth, KV v2):**

```php
'vault' => [
    'driver'     => 'vault',
    'address'    => env('VAULT_ADDR', 'https://vault.example.com'),
    'token'      => env('VAULT_TOKEN'),
    'auth'       => 'token',
    'kv_version' => 2,
],
```

**Config (AppRole auth, KV v1):**

```php
'vault' => [
    'driver'        => 'vault',
    'address'       => env('VAULT_ADDR', 'https://vault.example.com'),
    'auth'          => 'approle',
    'role_id'       => env('VAULT_ROLE_ID'),
    'secret_id'     => env('VAULT_SECRET_ID'),
    'approle_mount' => 'approle',  // default
    'kv_version'    => 1,
],
```

**Usage:**

```php
// KV v2: mount is "secret", path is "myapp/stripe"
// The driver automatically injects /data/ into the path for KV v2
redacted('vault://secret/myapp/stripe')

// Extract a specific key from the Vault secret (which is always a map)
redacted('vault://secret/myapp/stripe#secret_key')
redacted('vault://secret/myapp/stripe#public_key')
```

**KV v2 path rewriting:** Vault KV v2 requires `/data/` after the mount name in API calls. The driver handles this transparently. If your mount is `secret` and your path is `myapp/stripe`, the API call goes to `secret/data/myapp/stripe`. You don't need to think about this.

**A note on `vault_url`:** Default is `https://vault.example.com`. Make absolutely sure your address starts with `https://` in production. Sending Vault tokens over plaintext HTTP is an unambiguous security incident.

---

### Infisical

Reads from [Infisical](https://infisical.com/), the open-source secrets management platform. Uses Universal Auth (clientId + clientSecret → JWT access token, cached per-process).

**Config:**

```php
'infisical' => [
    'driver'        => 'infisical',
    'client_id'     => env('INFISICAL_CLIENT_ID'),
    'client_secret' => env('INFISICAL_CLIENT_SECRET'),
    'workspace_id'  => env('INFISICAL_WORKSPACE_ID'),
    'environment'   => env('INFISICAL_ENVIRONMENT', 'prod'),
    'base_url'      => env('INFISICAL_URL', 'https://us.infisical.com'),
],
```

For EU cloud, set `base_url` to `https://eu.infisical.com`. For self-hosted Infisical, point `base_url` at your instance.

**Usage:**

```php
// Secret named DATABASE_URL in your Infisical workspace
redacted('infisical://DATABASE_URL')
```

The access token is fetched once per process and refreshed automatically before expiry. Your secrets are fetched via Infisical's v3 API with the workspace ID and environment from config.

---

### Doppler

Reads from [Doppler](https://www.doppler.com/), the secrets manager for developer teams.

**Config:**

```php
'doppler' => [
    'driver'  => 'doppler',
    'token'   => env('DOPPLER_TOKEN'),   // service token, not personal token
    'project' => env('DOPPLER_PROJECT'),
    'config'  => env('DOPPLER_CONFIG', 'prd'),
],
```

Use a **service token**, not a personal API token. Service tokens are scoped to a specific project + config and are the right credential for production use.

**Usage:**

```php
// Secret named DATABASE_URL in your Doppler project/config
redacted('doppler://DATABASE_URL')
```

**Prefetching:** Doppler has a bulk download endpoint (`/v3/configs/config/secrets/download?format=json`) that returns all secrets in one call as a flat JSON object. The `redacted:cache` command uses this automatically — one API call, all your secrets, done. Individual `get()` calls hit the per-secret endpoint.

---

### Env driver

Wraps `getenv()`. Mostly useful as the default driver for local development — you keep secrets in `.env` and let the driver pull them through the `redacted()` interface, so your config files don't need to know whether you're running locally or against a real secret store.

```php
'env' => ['driver' => 'env'],
```

```php
redacted('env://DB_HOST')  // equivalent to getenv('DB_HOST')
```

Note: an env var explicitly set to empty string is treated as not-found (returns null / fallback). A var that isn't set at all also returns null. Both are consistent with how you'd generally expect a "missing" value to behave.

---

### Array driver

In-memory driver. Pre-loaded with whatever values you give it. Primarily for testing, but occasionally useful for seeding known values in a local/CI environment.

```php
'array' => [
    'driver' => 'array',
    'values' => [
        'some-key' => 'some-value',
    ],
],
```

```php
redacted('array://some-key')
```

In tests, you'll typically use `Redacted::fake()` rather than configuring this directly — see the [Testing](#testing) section.

---

## Configuration reference

Publish and open `config/redacted.php`. The full structure:

```php
return [
    // The default driver to use when the scheme in a URI doesn't match any configured driver.
    // In practice, you'll usually use explicit schemes everywhere, but this is the fallback.
    'default' => env('REDACTED_DRIVER', 'env'),

    'drivers' => [
        'ssm' => [
            'driver'  => 'ssm',
            'region'  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key'     => env('AWS_ACCESS_KEY_ID'),      // omit to use credential chain
            'secret'  => env('AWS_SECRET_ACCESS_KEY'),  // omit to use credential chain
        ],

        'asm' => [
            'driver' => 'asm',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],

        'akv' => [
            'driver'               => 'akv',
            'vault_url'            => env('AZURE_KEY_VAULT_URL'),    // https://myvault.vault.azure.net
            'tenant_id'            => env('AZURE_TENANT_ID'),
            'client_id'            => env('AZURE_CLIENT_ID'),
            'client_secret'        => env('AZURE_CLIENT_SECRET'),
            'use_managed_identity' => false,
        ],

        'gcp' => [
            'driver'      => 'gcp',
            'project'     => env('GOOGLE_CLOUD_PROJECT'),
            'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'), // omit for ADC
        ],

        'vault' => [
            'driver'        => 'vault',
            'address'       => env('VAULT_ADDR', 'https://vault.example.com'),
            'token'         => env('VAULT_TOKEN'),
            'auth'          => 'token',    // 'token' or 'approle'
            'role_id'       => env('VAULT_ROLE_ID'),
            'secret_id'     => env('VAULT_SECRET_ID'),
            'approle_mount' => 'approle',
            'kv_version'    => 2,          // 1 or 2
        ],

        'infisical' => [
            'driver'        => 'infisical',
            'client_id'     => env('INFISICAL_CLIENT_ID'),
            'client_secret' => env('INFISICAL_CLIENT_SECRET'),
            'workspace_id'  => env('INFISICAL_WORKSPACE_ID'),
            'environment'   => env('INFISICAL_ENVIRONMENT', 'prod'),
            'base_url'      => env('INFISICAL_URL', 'https://us.infisical.com'),
        ],

        'doppler' => [
            'driver'  => 'doppler',
            'token'   => env('DOPPLER_TOKEN'),
            'project' => env('DOPPLER_PROJECT'),
            'config'  => env('DOPPLER_CONFIG', 'prd'),
        ],

        'env'   => ['driver' => 'env'],
        'array' => ['driver' => 'array', 'values' => []],
    ],

    'cache' => [
        // Which Laravel cache store to use for Layer 2 caching.
        // 'file' works fine for single-server setups.
        // 'redis' is recommended for multi-server or Octane deployments.
        'store' => env('REDACTED_CACHE_STORE', 'file'),

        // How long resolved values are cached in the Laravel cache store, in seconds.
        // Irrelevant if you're using config:cache in production (values are baked in at cache time).
        'ttl' => 3600,

        // Prefix for all cache keys. If you have multiple apps sharing a cache store,
        // set a unique prefix per app to avoid collisions.
        'prefix' => 'redacted:',
    ],

    // How many characters of a secret to show in redacted:list output.
    // The rest is replaced with asterisks.
    'mask_length' => 4,
];
```

---

## The three-layer cache

When `config:cache` isn't in play (local dev, dynamic resolution), resolved values travel through three cache layers before hitting the remote store:

**Layer 1 — Static process cache**

`Resolver::$cache` is a plain PHP static array keyed by `{scheme}:{path}`. It's checked first on every call, costs nothing, and persists for the lifetime of the PHP process. Once a secret is resolved, it's free to access for the rest of that request (and every subsequent request in the same worker process under FPM or Octane).

Cleared by `php artisan redacted:clear --static` or `Resolver::clearStaticCache()` in your code.

**Layer 2 — Laravel cache store**

Configured by `cache.store` and `cache.ttl`. Uses whatever cache store you've configured — file, Redis, Memcached, whatever. Survives worker recycling, deploy restarts (if on a shared store), and anything else that kills the static cache. The `redacted:cache` command bulk-populates this layer. Lazy population happens on the first driver call for a key.

Cache keys look like: `{prefix}{scheme}:{path}` → `redacted:ssm:/prod/myapp/db_password`

Cleared (for specific keys) by `php artisan redacted:clear`.

**Layer 3 — Remote driver**

The actual API call. Only reached if both caches miss. On success, the value is written back to both Layer 1 and Layer 2.

The cache key uses `{scheme}:{path}` without the `#fragment`. This is intentional: `asm://prod/db#host` and `asm://prod/db#password` share a single cached blob (one API call), with key extraction applied on every read. Efficient.

---

## Production: config:cache

This is the recommended production workflow:

```bash
php artisan config:cache
```

Laravel evaluates all your config files, calls `redacted()` for each secret, and writes the resolved values to `bootstrap/cache/config.php`. After this, your app reads config from that file — no drivers, no cache stores, no network calls. The resolved values are just there.

**The upside:** Zero runtime overhead, zero API credentials needed on the web server, zero latency. From Laravel's perspective, there's no difference between a config value that came from `env()` and one that came from `redacted()`.

**The deployment workflow:**

```bash
# During deployment, before going live:
php artisan config:cache

# Rotate a secret? Regenerate the cache:
php artisan config:cache

# Need to force-refresh without a full deploy:
php artisan config:clear && php artisan config:cache
```

**What `redacted:cache` is for:** The `redacted:cache` Artisan command pre-warms the Laravel cache store (Layer 2). Use it if you're running without `config:cache` — for example, in an environment where config is dynamic, or during early bootstrapping before `config:cache` has run. It batch-fetches all secrets it can find by scanning your config files for `redacted()` calls.

```bash
php artisan redacted:cache          # warm the cache
php artisan redacted:cache --dry-run # see what would be fetched without fetching
```

---

## Artisan commands

### redacted:cache

Scans your config files for `redacted()` calls, batch-fetches the secrets from each driver, and writes the values to the configured cache store.

```bash
php artisan redacted:cache
php artisan redacted:cache --dry-run
```

The `--dry-run` flag shows you what would be fetched — paths, drivers, current cache status — without making any API calls or writing to cache. Good for CI sanity checks.

The command groups paths by scheme and calls each driver's `prefetch()` method, which means batch API calls wherever the driver supports it (SSM, ASM, Doppler all do). Your quota will thank you.

### redacted:clear

Clears cached values for all `redacted()` calls found in your config files.

```bash
php artisan redacted:clear           # clears Layer 2 (Laravel cache) for known keys
php artisan redacted:clear --static  # also clears the in-process static cache (Layer 1)
```

This is a targeted clear — it scans your config files to find the exact cache keys to remove, rather than flushing your entire cache store.

### redacted:list

Lists all `redacted()` calls found in your config files, their resolution status, and (optionally) their resolved values.

```bash
php artisan redacted:list
php artisan redacted:list --reveal         # show actual values (first N chars unmasked, rest ***)
php artisan redacted:list --driver=ssm     # filter to a specific driver
```

Output shows: the URI, which driver handles it, the value (masked by default), whether it's currently in cache, and which file/line it was found in.

---

## Testing

### Using fake()

The primary testing pattern is `Redacted::fake()`, which replaces real drivers with an in-memory map for the duration of a test.

```php
use Yamut\Redacted\Facades\Redacted;

Redacted::fake([
    'ssm:///prod/myapp/app-key'    => 'test-app-key',
    'asm://prod/myapp/db#host'     => '127.0.0.1',
    'asm://prod/myapp/db#password' => 'test-password',
    'vault://secret/stripe#key'    => 'sk_test_abc123',
    'doppler://API_KEY'            => 'test-api-key',
]);
```

`fake()` handles the `#fragment` grouping automatically — `asm://prod/myapp/db#host` and `asm://prod/myapp/db#password` are stored as a single JSON blob under `prod/myapp/db`, exactly as a real ASM driver would return them.

**Important:** `fake()` works during both phases of app booting:
- **Post-boot:** When `redacted()` is called in application code after the service provider has registered.
- **Early-boot (config loading):** When `redacted()` is called inside config files during `LoadConfiguration`, before service providers run. This is the typical use case and it's handled by registering fake drivers in a static registry that the resolver checks before anything else.

### TestCase setup

Here's the base `TestCase` you should use for any test that touches `redacted()`:

```php
use Orchestra\Testbench\TestCase as BaseTestCase;
use Yamut\Redacted\RedactedServiceProvider;
use Yamut\Redacted\Resolution\Resolver;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [RedactedServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use the array driver and array cache store so tests never hit real infrastructure
        $app['config']->set('redacted.default', 'array');
        $app['config']->set('redacted.cache.store', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Resolver::clearStaticCache();
    }

    protected function tearDown(): void
    {
        Resolver::clearStaticCache();
        $this->app->forgetInstance('redacted'); // reset the Manager singleton between tests
        parent::tearDown();
    }
}
```

The `clearStaticCache()` calls in `setUp` and `tearDown` are load-bearing. The static cache is process-level — if one test populates it and the next test doesn't clear it, you'll get the wrong value with no warning. Don't skip them.

The `forgetInstance('redacted')` in `tearDown` resets the Manager singleton between tests, which is necessary if you're using `fake()` — otherwise the fake drivers from test A will bleed into test B.

### Full test example

```php
use Yamut\Redacted\Facades\Redacted;

class MyFeatureTest extends TestCase
{
    public function test_database_config_resolves_from_fake(): void
    {
        Redacted::fake([
            'asm://prod/myapp/db#host'     => 'test-db.local',
            'asm://prod/myapp/db#password' => 'test-password',
        ]);

        $this->assertSame('test-db.local', redacted('asm://prod/myapp/db#host'));
        $this->assertSame('test-password', redacted('asm://prod/myapp/db#password'));
    }

    public function test_falls_back_when_secret_is_missing(): void
    {
        Redacted::fake([]); // empty fake — nothing resolves

        $this->assertSame('fallback-value', redacted('ssm:///prod/missing', 'fallback-value'));
    }

    public function test_closure_fallback(): void
    {
        Redacted::fake([]);

        $result = redacted('ssm:///prod/missing', fn() => 'computed-fallback');
        $this->assertSame('computed-fallback', $result);
    }
}
```

---

## Integration testing

The package ships with an integration test suite that runs against real infrastructure. These tests are excluded from the default `composer test` run — they only run when you explicitly invoke them and have the required credentials in your environment.

### Running the suite

```bash
composer test:integration
```

Without credentials, all integration tests skip automatically (no failures, no errors). With credentials, they make real API calls.

### SSM integration tests

The test uses the SDK credential chain — no explicit key/secret required. Set `AWS_PROFILE` for SSO or named profiles, or omit it entirely when running on AWS infrastructure with an IAM role.

```bash
# SSO / named profile
AWS_PROFILE=your-sso-profile \
AWS_DEFAULT_REGION=us-east-1 \
REDACTED_TEST_SSM_PATH=//your/param/path \
composer test:integration

# IAM role / instance profile (e.g. on EC2 or ECS) — no credentials needed at all
AWS_DEFAULT_REGION=us-east-1 \
REDACTED_TEST_SSM_PATH=//your/param/path \
composer test:integration
```

`REDACTED_TEST_SSM_PATH` is the URI suffix for a parameter that exists in your account. Use double-slash prefix for absolute SSM paths (which becomes `ssm:///your/param/path`). The tests verify that an existing parameter resolves to a non-empty value, that a non-existent parameter returns `null`, and that a non-existent parameter with a fallback returns that fallback.

### Adding integration tests for other drivers

Extend `IntegrationTestCase`, declare `requiredEnv()`, and override `getEnvironmentSetUp()` to configure the driver with real credentials.

```php
class AsmDriverTest extends IntegrationTestCase
{
    protected function requiredEnv(): array
    {
        return ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'REDACTED_TEST_ASM_SECRET'];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('redacted.default', 'asm');
        $app['config']->set('redacted.drivers.asm', [
            'driver' => 'asm',
            'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
            'key'    => getenv('AWS_ACCESS_KEY_ID'),
            'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
        ]);
    }

    #[Test]
    public function it_resolves_a_secret_from_asm(): void
    {
        $name  = getenv('REDACTED_TEST_ASM_SECRET');
        $value = redacted("asm://{$name}");

        $this->assertNotNull($value);
    }
}
```

`IntegrationTestCase::setUp()` calls `markTestSkipped()` for the first missing env var it finds, which prevents the test from running (and prevents `getEnvironmentSetUp()` from being called with `null` credential values).

### Test suite separation

`composer test` runs only `Unit` and `Feature` suites — integration tests are never included in CI unless you explicitly add the credentials and call `composer test:integration`.

```bash
composer test              # Unit + Feature only — safe for CI without cloud creds
composer test:integration  # Integration only — requires real credentials
```

---

## The #fragment syntax

When a secret store holds a JSON blob, you can extract specific keys without multiple API calls.

Suppose your ASM secret `prod/myapp/db` contains:

```json
{
  "host": "db.prod.internal",
  "port": "5432",
  "name": "myapp_production",
  "username": "myapp",
  "password": "hunter2"
}
```

You can pull individual fields:

```php
// config/database.php
'pgsql' => [
    'host'     => redacted('asm://prod/myapp/db#host'),
    'port'     => redacted('asm://prod/myapp/db#port'),
    'database' => redacted('asm://prod/myapp/db#name'),
    'username' => redacted('asm://prod/myapp/db#username'),
    'password' => redacted('asm://prod/myapp/db#password'),
],
```

**One API call.** All five `redacted()` calls share a single cached fetch of the blob. The fragment key is applied locally after decoding. The cache key is `{scheme}:{path}` — the `#fragment` is intentionally excluded so the blob is cached once and reused.

This works with any driver that returns JSON: ASM (naturally), HashiCorp Vault (KV secrets are always maps), and any custom driver that returns a JSON string from `get()`.

If the raw value isn't valid JSON, or if the requested key doesn't exist in the decoded object, the fallback is returned.

---

## Fallback values

The second argument to `redacted()` is the fallback — returned when the secret can't be resolved for any reason (not found, driver error, network timeout, misconfiguration).

```php
// Static fallback
redacted('ssm:///prod/myapp/key', 'default-value')

// Closure fallback — only called if the secret can't be resolved
redacted('ssm:///prod/myapp/key', fn() => computeExpensiveDefault())

// Chain with env() for local development
redacted('ssm:///prod/myapp/db-password', env('DB_PASSWORD'))
```

The closure form is useful when computing the fallback has side effects or is expensive — the closure is only invoked if it's actually needed.

**On failures:** The resolver catches all exceptions internally. A network outage, an expired credential, a malformed response — all of these fall through to the fallback silently. This is intentional: you don't want a transient API hiccup to crash your app boot. The tradeoff is that misconfiguration can be quiet. If something isn't resolving and you don't know why, `redacted:list` is your first debugging stop.

---

## Octane and long-running processes

If you're running Laravel Octane (Swoole, RoadRunner, FrankenPHP), be aware of how the static cache behaves.

The in-process static cache (`Resolver::$cache`) persists across requests within the same worker. This is intentional and generally desirable — you don't want to re-hit your secret store on every request. But it means:

**Secret rotation doesn't take effect immediately.** If you rotate a credential in Vault or SSM, the in-process cached value stays stale until the worker is recycled. Under FPM this is fine since workers are short-lived; under Octane they can run for hours.

**Mitigations:**

Option 1: Use `config:cache` in production. The static cache becomes irrelevant because `redacted()` is never called at runtime.

Option 2: Use a short TTL in the Laravel cache store and a shared store (Redis), and arrange for workers to be recycled periodically. Workers that restart will miss the static cache and fall through to Layer 2.

Option 3: Register a listener to clear the static cache on each request:

```php
// In a service provider's boot() method
use Illuminate\Foundation\Http\Events\RequestHandled;
use Yamut\Redacted\Resolution\Resolver;

$this->app['events']->listen(RequestHandled::class, function () {
    Resolver::clearStaticCache();
});
```

This trades the performance benefit of the static cache for freshness. Fine for low-traffic apps; think twice for high-throughput ones.

**Multi-server deployments:** Use `cache.store: redis` (or any shared cache store) for Layer 2. With a file cache, each server has its own cache and you can't warm them all with one `redacted:cache` command.

---

## Custom drivers

You can add your own driver by implementing `DriverInterface` and registering it via the `extend()` method on the Manager.

**The interface:**

```php
namespace Yamut\Redacted\Contracts;

interface DriverInterface
{
    public function get(string $path): ?string;
    public function prefetch(array $paths): array; // path => value|null
    public function flush(): void;
}
```

**Implementation:**

```php
use Yamut\Redacted\Drivers\AbstractDriver;

class MyVaultDriver extends AbstractDriver
{
    public function get(string $path): ?string
    {
        // $this->config contains your driver's config block from redacted.php
        $apiKey = $this->config['api_key'] ?? throw new \RuntimeException('api_key required');
        
        // ... fetch the secret ...
        
        return $value; // null if not found
    }

    // prefetch() defaults to N sequential get() calls from AbstractDriver.
    // Override it if your store has a batch endpoint.
    
    // flush() defaults to a no-op. Override to close connections, clear tokens, etc.
}
```

**Registration:**

```php
// In a service provider
use Yamut\Redacted\Facades\Redacted;

Redacted::extend('myvault', function ($app) {
    $config = $app['config']->get('redacted.drivers.myvault', []);
    return new MyVaultDriver($config);
});
```

```php
// config/redacted.php
'drivers' => [
    // ... other drivers ...
    'myvault' => [
        'driver'  => 'myvault',
        'api_key' => env('MYVAULT_API_KEY'),
        'url'     => env('MYVAULT_URL'),
    ],
],
```

Then use `myvault://path/to/secret` in your config files.

**Early-boot note:** Custom drivers registered via `extend()` are only available after the service provider has registered. If `redacted()` is called during config loading (before service providers run), custom drivers won't be available and the resolver will fall back to the `default` driver from config. This is the same behavior as the built-in drivers — nothing special to worry about unless you're doing something unusual.

---

## License

MIT
