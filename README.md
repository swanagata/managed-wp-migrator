# Managed WP Migrator

The WordPress plugin a customer installs on their old website to copy it into
Managed WP. They paste the migration key from the Managed WP migration page
under **Tools → Migrate to Managed WP**; Managed WP then pulls the website in
small, HMAC-signed requests. Nothing on the old website changes.

- Single file: `managed-wp-migrator.php`. PHP 7.4+ and WordPress 5.0+.
- Managed WP serves `managed-wp-migrator.zip` from the **latest release** of
  this repository to its customers.

## Releasing

1. Bump `Version:` in the plugin header and `const VERSION` to the same value.
2. Tag `v<version>` and push the tag. The Release workflow checks the tag
   matches both, builds the zip and publishes the release.

## Contract with Managed WP

Keep these in step with the Managed WP app (`App\Support\SiteMigrationSignature`
and the migration driver `resources/coolify/wordpress/migration/managed-wp-migrate.php`):

- Key format: `mwp1_` + base64url JSON `{u: app URL, k: source token, s: secret}`.
- Signature: HMAC-SHA256 with the secret over
  `METHOD\npath\ntimestamp\nnonce\nsha256(body)`, sent as `X-MWP-Timestamp`,
  `X-MWP-Nonce` and `X-MWP-Signature`. The path is the URL path for Managed WP
  endpoints and the REST route (`/managed-wp-migrator/v1/{endpoint}`) for this
  plugin's endpoints.
- Endpoints: `info`, `tables`, `table-chunk`, `files`, `file-chunk`, `finish`,
  over `?rest_route=` or `wp-admin/admin-ajax.php?action=managed_wp_migrator&endpoint=`.
