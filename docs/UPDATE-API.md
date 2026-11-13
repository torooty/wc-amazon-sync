# Private updates — module-updates-api

Health: `GET https://api.webtest24.de/api/v1/health`

## Update URI

`https://api.webtest24.de/api/v1/plugins/wc-amazon-sync`

Homepage: `https://github.com/torooty/wc-amazon-sync`

### Expected JSON

```json
{
  "slug": "wc-amazon-sync",
  "name": "Amazon Sync",
  "version": "1.0.5",
  "download_url": "https://api.webtest24.de/api/v1/plugins/wc-amazon-sync/download/1.0.5",
  "requires": "6.0",
  "requires_php": "8.1",
  "tested": "6.8",
  "homepage": "https://github.com/torooty/wc-amazon-sync"
}
```

ZIP root must be `wc-amazon-sync/` with `wc-amazon-sync.php`. Remote version must be strictly greater than installed.