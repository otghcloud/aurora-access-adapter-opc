# Aurora Access Adapter: OPC

Aurora Access OPC adapter package for integrating OPC/OPC-UA-backed sources with the Aurora Access Control core package.

## Requirements

- PHP 8.2+
- Laravel 12+
- Installed `otghcloud/aurora-access-core` package

## Installation

```bash
composer require otghcloud/aurora-access-adapter-opc
```

## What This Adapter Provides

- OPC source integration for Access Control
- Input action dispatch support for configured Access Bindings
- Adapter capability registration for source and binding flows

## Configuration

1. Ensure the Aurora Access core package is installed and configured.
2. Install this adapter package with Composer.
3. Configure adapter-specific source settings from the Access Control admin panel.

## Quick Start

```bash
php artisan migrate --force
php artisan optimize:clear
```

Then in the admin panel:

1. Create or edit an Access Source.
2. Select an OPC adapter type where available.
3. Configure source connection details and save.

## Compatibility Notes

- Keep adapter and core versions aligned to stable release lines.
- Use tagged releases in production.

## License

MIT