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

## WordPress Asset Import Tool

### Purpose

This package includes a powerful command-line tool for importing WordPress assets directly into the Neos Media module. The tool recursively scans the specified directory, imports each file as a resource, and creates an Asset that can be assigned to either a specified Asset Collection or a new or existing Asset Tag. This functionality works alongside the existing HTTP middleware for a complete WordPress migration solution.

### Prerequisites

If you want to import assets into a collection, you need to create an Asset Collection in the Neos Media module before running the import command. You can do this through the Neos backend interface.

Alternatively, you can use the `--tag` parameter instead, which doesn't require any prerequisites as tags will be created automatically if they don't exist.

### Usage

To import assets from a WordPress uploads directory, run the following command:

```bash
./flow assets:import --path /path/to/uploads --collection "My Collection"
# OR
./flow assets:import --path /path/to/uploads --tag "My Tag"
```

#### Parameters:

- `--path`: The absolute path to the root of the WordPress 'uploads' directory. This argument is required.
- `--collection`: Title of the asset collection to import assets into. This collection must exist. This is optional.
- `--tag`: The label of the asset tag to assign to imported assets. If the tag doesn't exist, it will be created automatically. This is optional.
- `--type`: An optional filter to import only files of a specific type. If omitted, all files are processed. Available types: 'image', 'document'.

Note: You must specify either `--collection` or `--tag`.

#### Examples:

Import only images into an "Images" collection:
```bash
./flow assets:import --path /path/to/your/wp-content/uploads --collection "Images" --type image
```

Import only documents into a "Documents" collection:
```bash
./flow assets:import --path /path/to/your/wp-content/uploads --collection "Documents" --type document
```

Import all files without filtering into a collection:
```bash
./flow assets:import --path /path/to/your/wp-content/uploads --collection "All Assets"
```

Import all assets and assign them a "Migrated" tag:
```bash
./flow assets:import --path /path/to/your/wp-content/uploads --tag "Migrated"
```

Import only images and assign them an "Imported Images" tag:
```bash
./flow assets:import --path /path/to/your/wp-content/uploads --tag "Imported Images" --type image
```

### Import Process

The import process follows these steps:

1. Validates the provided path and arguments (either collection or tag must be specified)
2. If a collection is specified, checks if it exists
3. If a tag is specified, finds it or creates a new one if it doesn't exist
4. Counts the total number of files to be processed
5. Displays a progress bar during the import
6. For each file:
   - Imports the file as a resource
   - Checks if an asset with the same resource already exists (to prevent duplicates)
   - Creates a new Asset with appropriate metadata
   - Adds the asset to the specified collection or assigns the specified tag to the asset
7. Provides a detailed summary report after completion

### Output

The command provides a comprehensive summary report:

- Total files found
- Number of new assets successfully imported
- Number of files skipped (because they already existed as assets)
- Detailed error messages for any issues encountered during import

### Error Handling

The command handles various error scenarios:

- Fatal errors:
  - If the provided path is not a valid directory or is not readable
  - If neither collection nor tag is specified
  - If both collection and tag are specified at the same time
  - If the specified collection doesn't exist
- Non-fatal errors: If some subdirectories cannot be read due to permissions or if there are issues importing specific files
