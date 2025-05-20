# CraftRateLimiter

Rate Limiter for Controller Actions

## Config
Copy the `config.php` file to your config directory as `craft-rate-limiter.php` and set the rate limit values. \
These are two example configs:

```php
<?php

use webhubworks\craftratelimiter\models\RateLimiterConfig;

return [
    '*' => [
    
        RateLimiterConfig::make()
            ->requestsPerSecond(1)
            ->requestsPerMinute(2)
            ->requestsPerHour(20)
            ->requestMethods(['POST', 'PUT', 'PATCH', 'DELETE'])
            ->addControllerAction(
                controllerClass: \craft\controllers\UsersController::class,
                controllerActions: ['login']
            )
            ->build(),
            
        RateLimiterConfig::make()
            ->requestsPerMinute(2)
            ->requestMethods(['POST', 'PUT', 'PATCH', 'DELETE'])
            ->anyActionOfController(\craft\controllers\SomeOtherController::class)
            ->build(),
    ],
];
```