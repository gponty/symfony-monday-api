# Symfony Monday API

This is a Symfony 6/7 Bundle that helps you use the Monday.com API v2:  
https://developer.monday.com/apps/docs/mondayapi  
It uses version `2023-10` of the API.

## Installation

Install the package via Composer:

```bash
composer require gponty/symfony-monday-api-bundle
```

## Usage

Inject the service into your controller:

```php
public function __construct(
    readonly MondayApiService $mondayApiService
) {}
```

Use the service like this:

```php
$query = '{
    boards(ids: 123456789) {
        id
        name
        groups {
            id
            title
            items_page(limit: 100, page: 1) {
                cursor
                items {
                    id
                    name
                }
            }
        }
    }
}';

$this->mondayApiService->setApiKey('your-api-key-here');
$response = $this->mondayApiService->request($query);
```

## Changelog

### Version 1.0.7

You must now call `setApiKey()` before using the `request()` method.  
You MUST also remove the `config/packages/monday.yaml` file if it exists.

## License

This bundle is released under the MIT License. See the LICENSE file for more information.
