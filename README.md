# Amazon Sync Pro for WooCommerce

Modern WooCommerce plugin for synchronizing products, orders, and inventory with Amazon SP-API.

## Features

- **Amazon SP-API Integration**: Modern Amazon Selling Partner API (not deprecated MWS)
- **Product Synchronization**: Import and export products between WooCommerce and Amazon
- **Inventory Management**: Bidirectional inventory sync with configurable direction
- **Order Management**: Import orders from Amazon to WooCommerce
- **Background Processing**: Uses Action Scheduler for efficient background sync
- **Multi-language Support**: Russian, German, and English interface
- **Product Mapping**: Flexible mapping between WooCommerce and Amazon products (SKU, ASIN, EAN, custom fields)
- **Automatic Sync**: Auto-sync on product save with configurable frequency

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+

## Installation

1. Upload the `wc-amazon-sync` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Amazon Sync** > **Settings** to configure your Amazon SP-API credentials

## Configuration

### Amazon SP-API Setup

1. **Region**: Select your Amazon SP-API region (EU, US, etc.)
2. **Seller ID**: Your Amazon Seller ID
3. **Marketplace ID**: Your Amazon Marketplace ID (e.g., A1PA6795UKMFR9 for Germany)
4. **Client ID & Secret**: Login with Amazon (LWA) credentials
5. **Refresh Token**: Amazon SP-API Refresh Token
6. **Sandbox Mode**: Enable for testing

### Product Mapping

Configure how products are mapped between WooCommerce and Amazon:
- **SKU**: Match by product SKU (default)
- **ASIN**: Match by Amazon ASIN
- **EAN**: Match by EAN code
- **Custom Field**: Use a custom meta field

### Synchronization Settings

- **Inventory Direction**: Choose sync direction (WooCommerce → Amazon, Amazon → WooCommerce, or Bidirectional)
- **Sync Frequency**: How often to run background synchronization (minimum 300 seconds)
- **Auto Sync on Save**: Automatically sync changes when a product is saved in WooCommerce

### Interface Language

Choose the plugin interface language:
- Auto (use site language)
- English (en_US)
- German (de_DE)
- Russian (ru_RU)

## Usage

### Import Products from Amazon

1. Go to **Amazon Sync** > **Import Products**
2. Click **Load Products from Amazon**
3. Map Amazon products to existing WooCommerce products or create new ones
4. Save mappings or import selected products

### Export Products to Amazon

1. Go to **Amazon Sync** > **Export Products**
2. View all mapped products
3. Click **Sync Now** for individual products or **Sync All Products** for bulk sync

### Manage Product Mappings

1. Go to **Amazon Sync** > **Product Mapping**
2. View all product mappings
3. Delete mappings as needed

## Architecture

The plugin follows modern PHP architecture principles:

- **PSR-4 Autoloading**: Namespace-based autoloading
- **SOLID Principles**: Separation of concerns, dependency injection
- **Layered Architecture**: Domain, Service, Infrastructure, Presentation layers
- **WordPress Standards**: Follows WordPress Coding Standards

### Directory Structure

```
wc-amazon-sync/
├── src/
│   ├── Plugin.php              # Main plugin class
│   ├── Admin/                  # Admin UI components
│   ├── Settings/               # Settings management
│   ├── Amazon/                 # Amazon SP-API integration
│   ├── WooCommerce/            # WooCommerce integration
│   ├── Background/             # Background jobs
│   ├── Database/               # Database operations
│   ├── Rest/                   # REST API
│   ├── Localization/           # i18n
│   ├── Logger/                 # Logging
│   └── UI/                     # UI assets
├── assets/                     # CSS and JS files
├── languages/                  # Translation files
└── wc-amazon-sync.php          # Main plugin file
```

## Logging

Logs are stored in `wp-content/uploads/wc-amazon-sync-logs/`:
- `wc-amazon-sync-info-YYYY-MM-DD.log`: Info messages
- `wc-amazon-sync-error-YYYY-MM-DD.log`: Error messages
- `wc-amazon-sync-debug-YYYY-MM-DD.log`: Debug messages (only if WP_DEBUG is enabled)

## Security

- All admin actions check `manage_woocommerce` capability
- All forms use WordPress nonces
- All input is sanitized before storage
- All output is escaped before display
- API credentials are stored securely

## Development

### Composer

The plugin uses Composer for PSR-4 autoloading. Run:

```bash
composer install
```

### Translation

Translation files are located in `languages/`:
- `wc-amazon-sync.pot`: Template file
- `wc-amazon-sync-en_US.po/mo`: English
- `wc-amazon-sync-de_DE.po/mo`: German
- `wc-amazon-sync-ru_RU.po/mo`: Russian

To add translations, edit the `.po` files and compile to `.mo` using `msgfmt`.

## Support

For support and feature requests, please contact the plugin author.

## License

GPL-2.0-or-later

