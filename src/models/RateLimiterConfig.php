<?php

namespace webhubworks\craftratelimiter\models;

class RateLimiterConfig
{
    private array $config = [
        'perMinute' => 10,
        'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],
        'actions' => [],
    ];

    public static function make(): self
    {
        return new self();
    }

    public function requestsPerMinute(int $requests): self
    {
        $this->config['perMinute'] = $requests;
        return $this;
    }

    public function requestMethods(array $methods): self
    {
        $this->config['methods'] = $methods;
        return $this;
    }

    public function addControllerAction(string $controllerClass, array $actions): self
    {
        $this->config['actions'][$controllerClass] = $actions;
        return $this;
    }

    public function build(): array
    {
        return $this->config;
    }
}
