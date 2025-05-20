<?php

namespace webhubworks\craftratelimiter\models;

class RateLimiterConfig
{
    private array $config = [
        'numberOfRequestsPerSecond' => null,
        'numberOfRequestsPerMinute' => null,
        'numberOfRequestsPerHour' => null,
        'requestMethods' => ['POST', 'PUT', 'PATCH', 'DELETE'],
        'controllerActions' => null,
    ];

    public static function make(): self
    {
        return new self();
    }

    public function requestsPerSecond(int $numberOfRequestsPerSecond): self
    {
        $this->config['numberOfRequestsPerSecond'] = $numberOfRequestsPerSecond;
        return $this;
    }

    public function requestsPerMinute(int $numberOfRequestsPerMinute): self
    {
        $this->config['numberOfRequestsPerMinute'] = $numberOfRequestsPerMinute;
        return $this;
    }

    public function requestsPerHour(int $numberOfRequestsPerHour): self
    {
        $this->config['numberOfRequestsPerHour'] = $numberOfRequestsPerHour;
        return $this;
    }

    public function requestMethods(array $requestMethods): self
    {
        $this->config['requestMethods'] = $requestMethods;
        return $this;
    }

    public function addControllerAction(string $controllerClass, array $controllerActions): self
    {
        $this->config['controllerActions'][$controllerClass] = $controllerActions;
        return $this;
    }

    public function anyActionOfController(string $controllerClass): self
    {
        $this->config['controllerActions'][$controllerClass] = '*';
        return $this;
    }

    public function build(): array
    {
        return $this->config;
    }
}
