# Symfony Monday Api

This is a Symfony 6 Bundle helps you to use monday API v2 : https://developer.monday.com/apps/docs/mondayapi.
It use version 2023-10.

## Installation

**1** Add to composer.json to the `require` key

``` shell
    composer require gponty/symfony-monday-api-bundle
```

**2** Add to local .env file

``` shell
    MONDAY_API_KEY=your_token
    
```

**3** Add to framework config file config/packages/framework.yml

``` shell
monday:
    api_key: '%env(MONDAY_API_KEY)%'
```


## Usage

Inject the service in your controller :

``` php
    public function __construct(readonly MondayApiService $mondayApiService)
    { }
```

Use the service :

``` php
    $query = '{
              boards(ids: 123456789) {
                id
                name
                groups {
                  id
                  title
                items_page(limit: 100, page:1) {
                    cursor
                    items{
                        id
                        name
                    }
                }
              }
            }';
    $response = $this->mondayApiService->request($query);
```

## License

This bundle is under the MIT license. See the complete license in the bundle.
