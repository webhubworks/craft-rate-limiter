<?php

namespace webhubworks\craftratelimiter\events;

use yii\base\Event;

class RateLimitExceededEvent extends Event
{
    public string $requestMethod;
    public ?string $controllerClass;
    public ?string $actionId;
    public ?string $urlPattern;
    public ?string $urlPath;
    public string $triggeredInterval;
    public ?int $numberOfRequestsPerSecond;
    public ?int $numberOfRequestsPerMinute;
    public ?int $numberOfRequestsPerHour;
    public array $meta;
}