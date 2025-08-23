DEPLOYMENT GUIDE

This guide describes recommended steps to package, test, and deploy both the client plugin (`my-awesome-plugin`) and the server plugin (`wp-license-manager`). It assumes basic familiarity with WordPress, WP-CLI and GitHub Actions.

Assumptions

* You have a WordPress test site available (local, staging, or hosted).
* You can run `pwsh` (PowerShell) locally and have `zip` available or use built-in PowerShell compression.
* CI: GitHub Actions is available to build release artifacts.

1) Local testing (client and manager)

- Install both plugins into a test WordPress site (wp-content/plugins):

```pwsh
# Copy plugin folders to test WP site (adjust paths)
Copy-Item -Path .\my-awesome-plugin -Destination C:\path\to\wp\wp-content\plugins -Recurse -Force
Copy-Item -Path .\wp-license-manager -Destination C:\path\to\wp\wp-content\plugins -Recurse -Force
# Activate plugins with WP-CLI
wp plugin activate wp-license-manager --path="C:\path\to\wp"
wp plugin activate my-awesome-plugin --path="C:\path\to\wp"
```

- Configure the client plugin to point to the manager server. In the client plugin code or settings, set `api_url` to your test site's URL where `wp-license-manager` is active (e.g., https://local.test)

2) Packaging plugin zips (manual)

```pwsh
# From the repo root
Compress-Archive -Path .\my-awesome-plugin\* -DestinationPath .\my-awesome-plugin.zip -Force
Compress-Archive -Path .\wp-license-manager\* -DestinationPath .\wp-license-manager.zip -Force
```

Place those zips into your release artifacts or distribute to customers.

3) Automated packaging with GitHub Actions (recommended)

- Create a workflow `.github/workflows/release.yml` that runs on tags and creates zip artifacts. Basic steps:
  * Checkout
  * Set up PHP and composer (if used)
  * Run lint/tests
  * Zip plugin directories and upload artifacts

Example (conceptual snippet):

```yaml
name: Release
on:
  push:
    tags: ['v*']
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Zip my-awesome-plugin
        run: zip -r my-awesome-plugin.zip my-awesome-plugin
      - name: Zip manager
        run: zip -r wp-license-manager.zip wp-license-manager
      - name: Upload artifact
        uses: actions/upload-artifact@v3
        with:
          name: release-artifacts
          path: |
            my-awesome-plugin.zip
            wp-license-manager.zip
```

4) Deploying manager plugin to a production host

Option A - Standard WP host (shared / managed):

* Upload `wp-license-manager.zip` via the WordPress Admin > Plugins > Add New > Upload Plugin or unzip into `wp-content/plugins` and activate via WP-CLI.

Option B - Docker (recommended for isolation)

Create a `docker-compose.yml` for a local dev instance (example):

```yaml
version: '3.7'
services:
  db:
    image: mysql:5.7
    environment:
      MYSQL_ROOT_PASSWORD: example
      MYSQL_DATABASE: wordpress
  wordpress:
    image: wordpress:latest
    ports:
      - '8080:80'
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: root
      WORDPRESS_DB_PASSWORD: example
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - ./wp-content:/var/www/html/wp-content
    depends_on:
      - db
```

Run:

```pwsh
docker-compose up -d
```

Place manager plugin under `wp-content/plugins` and activate.

5) Configuration: point client to manager

* Set the client `api_url` to the base URL of where `wp-license-manager` is running (ensure trailing slash or the client's constructor uses `trailingslashit`).

6) Security and secrets

* Do not store admin credentials in repo or CI environment. Use GitHub Secrets for any private tokens.
* If you add an API key between client and server, transmit via HTTPS and verify on the server.

7) Smoke tests after deploy

* Visit Settings > {plugin} License and attempt Activate with a test license.
* Confirm server logs show activation and the client option is updated.
* Test deactivate flow.

8) Rollback

* For plugin-only issues, revert to previous plugin zip and re-activate the prior version via WP-CLI:

```pwsh
wp plugin deactivate my-awesome-plugin --path="C:\path\to\wp"
wp plugin unhook my-awesome-plugin --path="C:\path\to\wp"
# Replace files, then:
wp plugin activate my-awesome-plugin --path="C:\path\to\wp"
```

9) Troubleshooting tips

* If activation fails with "Invalid response from server", check the manager plugin is active and `wp-json/wplm/v1/activate` returns valid JSON.
* Use `wp_remote_post` debug logging in the manager temporarily or enable WP_DEBUG for more detailed errors.

10) Next steps / CI enhancements

* Add automated integration tests that spin up a test WP instance (via Docker) and verify activation and deactivation flows programmatically.
* Add a release note generator and changelog integration in the GitHub Actions pipeline.


End of deployment guide.
