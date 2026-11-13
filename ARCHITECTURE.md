# Amazon Sync Pro for WooCommerce - Architecture Plan

## Overview
Modern WooCommerce plugin for synchronizing products, orders, and inventory with Amazon SP-API.

## Architecture Principles
- **Layered Architecture**: Separation of concerns (Domain, Service, Infrastructure, Presentation)
- **PSR-4 Autoloading**: Namespace-based autoloading via Composer
- **SOLID Principles**: Single Responsibility, Dependency Injection
- **WordPress Standards**: Follows WordPress Coding Standards where applicable
- **Security**: Capability checks, nonces, sanitization/escaping
- **Performance**: Background processing, batch operations, caching

## Directory Structure

```
wc-amazon-sync/
├── wc-amazon-sync.php          # Main plugin file
├── composer.json                # PSR-4 autoloading
├── uninstall.php                # Cleanup on uninstall
├── src/                         # Source code
│   ├── Plugin.php              # Main plugin class
│   ├── Admin/
│   │   ├── AdminMenu.php       # Admin menu registration
│   │   ├── SettingsPage.php    # Settings page renderer
│   │   ├── Notices.php         # Admin notices
│   │   └── ProductsListTable.php # Amazon products table
│   ├── Settings/
│   │   ├── SettingsRepository.php # Settings CRUD
│   │   └── SettingsModel.php   # Settings DTO/model
│   ├── Amazon/
│   │   ├── AmazonApiClientInterface.php # API client interface
│   │   ├── AmazonApiClient.php # SP-API implementation
│   │   ├── ProductImporter.php # Import Amazon products
│   │   ├── ProductExporter.php # Export to Amazon
│   │   ├── InventorySyncService.php # Inventory sync logic
│   │   └── OrderSyncService.php # Order sync logic
│   ├── WooCommerce/
│   │   ├── ProductMapper.php   # WooCommerce ↔ Amazon mapping
│   │   ├── OrderMapper.php     # Order mapping
│   │   └── Hooks.php           # WC hooks (save_product, etc.)
│   ├── Background/
│   │   ├── Scheduler.php       # Action Scheduler registration
│   │   └── Jobs/
│   │       ├── SyncProductJob.php
│   │       ├── SyncInventoryJob.php
│   │       └── BulkPriceUpdateJob.php
│   ├── Database/
│   │   ├── Migration.php       # DB schema migrations
│   │   └── ProductMappingRepository.php # Product mapping CRUD
│   ├── Rest/
│   │   └── Routes.php          # Custom REST API routes
│   ├── Localization/
│   │   └── I18n.php            # Translation loader
│   ├── Logger/
│   │   └── Logger.php          # Custom logger
│   └── UI/
│       └── Assets.php          # Scripts/styles enqueuer
├── languages/                   # Translation files
│   ├── wc-amazon-sync.pot      # Template
│   ├── wc-amazon-sync-en_US.po
│   ├── wc-amazon-sync-de_DE.po
│   └── wc-amazon-sync-ru_RU.po
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
└── templates/                   # PHP templates (if needed)
    └── admin/
        └── settings-page.php
```

## Namespace Structure

```
WCAmazonSync\
├── Plugin                      # Main plugin class
├── Admin\                      # Admin UI components
├── Settings\                   # Settings management
├── Amazon\                     # Amazon SP-API integration
├── WooCommerce\                # WooCommerce integration
├── Background\                 # Background jobs
├── Database\                   # Database operations
├── Rest\                       # REST API
├── Localization\               # i18n
├── Logger\                     # Logging
└── UI\                         # UI assets
```

## Core Components

### 1. Plugin Bootstrap
- **wc-amazon-sync.php**: Entry point, checks requirements, loads autoloader
- **src/Plugin.php**: Main plugin class, service container, hook registration

### 2. Settings Management
- **SettingsRepository**: CRUD operations for plugin settings (stored in wp_options)
- **SettingsModel**: DTO for settings with validation

### 3. Amazon API Integration
- **AmazonApiClientInterface**: Contract for API client (allows mocking/testing)
- **AmazonApiClient**: SP-API implementation with OAuth 2.0, request signing
- Handles: Products, Inventory, Orders, Fulfillment

### 4. Product Mapping
- Custom DB table: `wp_wc_amazon_product_map`
  - Columns: id, wc_product_id, amazon_asin, mapping_type (sku/asin/ean), synced_at
- **ProductMappingRepository**: CRUD for mappings

### 5. Synchronization Services
- **ProductImporter**: Import from Amazon → WooCommerce
- **ProductExporter**: Export from WooCommerce → Amazon
- **InventorySyncService**: Bidirectional inventory sync
- **OrderSyncService**: Order import/export

### 6. Background Processing
- Uses WooCommerce Action Scheduler (included with WC)
- Jobs: SyncProduct, SyncInventory, BulkPriceUpdate
- **Scheduler**: Registers scheduled tasks

### 7. Admin Interface
- Settings page with tabs: API, Mapping, Sync, Language
- Products import/export pages
- Bulk actions support

### 8. Localization
- **I18n**: Loads translations, applies plugin locale
- Supports forced locale (independent from site locale)

### 9. Logging
- Custom logger to `wp-content/uploads/wc-amazon-sync-logs/`
- Rotating logs, different log levels

## Database Schema

### Product Mapping Table
```sql
CREATE TABLE wp_wc_amazon_product_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wc_product_id BIGINT UNSIGNED NOT NULL,
    amazon_asin VARCHAR(10) NOT NULL,
    mapping_type VARCHAR(20) DEFAULT 'sku',
    last_synced_at DATETIME NULL,
    sync_status VARCHAR(20) DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mapping (wc_product_id, amazon_asin),
    KEY idx_asin (amazon_asin),
    KEY idx_wc_product (wc_product_id)
);
```

## Settings Structure

```php
[
    'api' => [
        'region' => 'eu-west-1',
        'seller_id' => '',
        'marketplace_id' => '',
        'client_id' => '',
        'client_secret' => '',
        'refresh_token' => '',
        'use_sandbox' => true,
    ],
    'mapping' => [
        'default_mapping_type' => 'sku', // sku|asin|ean|custom_field
        'custom_field_name' => '_ean',
    ],
    'sync' => [
        'inventory_direction' => 'woo_to_amazon', // woo_to_amazon|amazon_to_woo|bidirectional
        'sync_frequency' => 3600, // seconds
        'auto_sync_on_save' => true,
    ],
    'ui' => [
        'language' => 'auto', // auto|en_US|de_DE|ru_RU
        'force_language' => false,
    ],
]
```

## Security Considerations

1. **Capability Checks**: All admin actions check `manage_woocommerce`
2. **Nonces**: All forms/actions use WordPress nonces
3. **Sanitization**: All input sanitized before storage
4. **Escaping**: All output escaped before display
5. **API Credentials**: Stored encrypted (using WordPress salts)

## Performance Optimizations

1. **Batch Operations**: Use Amazon SP-API batch endpoints
2. **Caching**: Cache API responses (transients)
3. **Background Jobs**: Heavy operations via Action Scheduler
4. **Lazy Loading**: Load classes only when needed
5. **Database Indexing**: Proper indexes on mapping table

