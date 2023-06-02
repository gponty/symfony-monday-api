# Symfony Monday Api

This is a Symfony 6 Bundle helps you to use monday API : https://developer.monday.com/apps/docs/mondayapi.

## Installation

Add to composer.json to the `require` key

``` shell
    composer require gponty/symfony-monday-api-bundle
```

## Usage

Inject the service in your controller :

``` php
    public function __construct(MondayApiService $mondayApiService)
    {
        $this->mondayApiService = $mondayApiService;
    }
```

Use the service :

``` php
    $query = 'query { boards { id name } }';
    $this->mondayApiService->makeQuery($query);
```

## License

This bundle is under the MIT license. See the complete license in the bundle:
