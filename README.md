# Automated Neos Redirects for WordPress Assets

Page not found? :bug: 404 errors are not good for your search engine reputation.
Especially after a migration.
But if you recently migrated from WordPress to Neos, we got you covered!

Our package provides a fallback redirect middleware, which checks if an asset is present,
where the title, caption or resource filename are similar to the old filename.

## Installation

`NEOSidekick.WordPressAssetRedirect` is available via Packagist. Add `"neosidekick/wordpressassetredirect" : "^1.0"` to the require section of the composer.json or run:

```bash
composer require neosidekick/wordpressassetredirect
```

We use semantic versioning, so every breaking change will increase the major version number.

## How does it work?

All you have to do is take all files from your `/wp-content/uploads/` folder and upload them in your Neos media library.
