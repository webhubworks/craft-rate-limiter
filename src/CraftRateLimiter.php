<?php

namespace webhubworks\craftratelimiter;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use webhubworks\craftratelimiter\events\RateLimitExceededEvent;
use webhubworks\craftratelimiter\models\RateLimiterConfig;
use webhubworks\craftratelimiter\models\Settings;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Event;
use yii\base\Module;
use yii\web\Response;

/**
 * CraftRateLimiter plugin
 *
 * @method static CraftRateLimiter getInstance()
 * @method Settings getSettings()
 * @author Webhubworks <support@webhub.de>
 * @copyright Webhubworks
 * @license MIT
 */
class CraftRateLimiter extends Plugin
{
    const RATE_LIMIT_EXCEEDED = 'rateLimitExceeded';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public array $configs = [];

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();
        $this->getConfig();

        $this->attachEventHandlers();
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('craft-rate-limiter/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Application::class,
            Module::EVENT_BEFORE_ACTION,
            function (ActionEvent $event) {

                if(empty($this->configs)){
                    return;
                }

                $request = Craft::$app->getRequest();
                if($request->isConsoleRequest){
                    return;
                }

                $controllerClass = get_class($event->action->controller); // e.g. 'craft\controllers\UsersController'
                $actionId = $event->action->id; // e.g. 'login'
                $requestMethod = $request->getMethod(); // e.g. 'POST'
                $urlPath = $request->getPathInfo(); // e.g. 'api/users/login' (with or without leading slash)

                /**
                 * We iterate over every config entry checking:
                 * - Does the request method match?
                 * - Does the controller match or the URL path match?
                 * - Does the controller action match (if controller-based)?
                 *
                 * If so, we check if the rate limit for this request method and controller action/URL path is exceeded.
                 */
                foreach ($this->configs as $config) {

                    if($config instanceof RateLimiterConfig){
                        $config = $config->build();
                    }

                    // First check if request method matches
                    if (!isset($config['requestMethods']) || !in_array($requestMethod, $config['requestMethods'])) {
                        continue;
                    }

                    $matchesController = false;
                    $matchesUrlPath = false;
                    $matchedUrlPattern = null;

                    // Check if controller/action matches (if configured)
                    if (!empty($config['controllerActions'])) {
                        if (in_array($controllerClass, array_keys($config['controllerActions']))) {
                            $controllerActions = $config['controllerActions'][$controllerClass];
                            if (
                                $controllerActions === '*'
                                || (is_array($controllerActions) && in_array($actionId, $controllerActions))
                            ) {
                                $matchesController = true;
                            }
                        }
                    }

                    // Check if URL path matches (if configured)
                    if (!empty($config['urlPaths'])) {
                        foreach ($config['urlPaths'] as $configPath) {
                            if ($this->matchesUrlPath($configPath, $urlPath)) {
                                $matchesUrlPath = true;
                                $matchedUrlPattern = $configPath;
                                break;
                            }
                        }
                    }

                    // Skip if neither controller nor URL path matches
                    if (!$matchesController && !$matchesUrlPath) {
                        continue;
                    }

                    $isRateLimited = $this->checkRateLimit(
                        method: $requestMethod,
                        controller: $matchesController ? $controllerClass : null,
                        action: $matchesController ? $actionId : null,
                        urlPath: $matchesUrlPath ? $urlPath : null,
                        urlPattern: $matchedUrlPattern,
                        config: $config
                    );

                    if($isRateLimited){
                        $event->isValid = false;

                        $this->handleRateLimitResponse();
                    }
                }
            }
        );
    }

    private function getConfig(): void
    {
        $this->configs = Craft::$app->getConfig()->getConfigFromFile('craft-rate-limiter');
    }

    private function registerLogTarget(): void
    {
        $dispatcher = Craft::getLogger()->dispatcher;

        if ($dispatcher) {
            $dispatcher->targets['craft-rate-limiter'] = new MonologTarget([
                'name' => 'craft-rate-limiter',
                'categories' => ['craft-rate-limiter'],
                'level' => LogLevel::INFO,
                'allowLineBreaks' => true,
                'maxFiles' => 10,
                'logContext' => false,
                'logVars' => [],
                'formatter' => new LineFormatter(
                    format: "%datetime% [%level_name%] %message%\n",
                    dateFormat: 'Y-m-d H:i:s',
                    allowInlineLineBreaks: true,
                ),
            ]);
        }
    }

    private function checkRateLimit(
        string $method,
        ?string $controller,
        ?string $action,
        ?string $urlPath,
        ?string $urlPattern,
        array $config
    ): bool
    {
        if($config['numberOfRequestsPerSecond'] !== null){
            $isRateLimited = $this->checkRateLimitPerInterval($method, $controller, $action, $urlPath, $urlPattern, $config['numberOfRequestsPerSecond'], 'second');
            if($isRateLimited){
                $this->dispatchEvent($method, $controller, $action, $urlPath, $config, 'second');
                return true;
            }
        }

        if($config['numberOfRequestsPerMinute'] !== null){
            $isRateLimited = $this->checkRateLimitPerInterval($method, $controller, $action, $urlPath, $urlPattern, $config['numberOfRequestsPerMinute'], 'minute');
            if($isRateLimited){
                $this->dispatchEvent($method, $controller, $action, $urlPath, $config, 'minute');
                return true;
            }
        }

        if($config['numberOfRequestsPerHour'] !== null){
            $isRateLimited = $this->checkRateLimitPerInterval($method, $controller, $action, $urlPath, $urlPattern, $config['numberOfRequestsPerHour'], 'hour');

            if($isRateLimited){
                $this->dispatchEvent($method, $controller, $action, $urlPath, $config, 'hour');
                return true;
            }
        }

        return false;
    }

    private function checkRateLimitPerInterval(string $method, ?string $controller, ?string $action, ?string $urlPath, ?string $urlPattern, int $numberOfRequests, string $interval): bool
    {
        /**
         * `Craft::$app->getRequest()->getUserIP()` should return the real IP address of the user
         * and not e.g. the IP of a load balancer or reverse proxy.
         */
        $ip = Craft::$app->getRequest()->getUserIP();
        $cache = Craft::$app->getCache();

        if (!$ip) {
            return false;
        }

        // Build cache key based on whether this is URL path-based or controller/action-based
        if ($urlPattern !== null) {
            // Use the pattern (not the actual URL) for the cache key
            // This ensures all requests matching the same pattern share the same rate limit
            // e.g., api/products/{sku}.json matches both api/products/ABC.json and api/products/XYZ.json
            $normalizedUrlPattern = ltrim($urlPattern, '/');
            $key = strtolower($method . '_urlpath_' . str_replace(['/', '{', '}'], '_', $normalizedUrlPattern) . '_' . $ip . '_' . $interval);
            $identifier = "URL path: $urlPath (pattern: $urlPattern)";
        } else {
            $key = strtolower($method . '_' . $controller . '_' . $action . '_' . $ip . '_' . $interval);
            $identifier = "controller: $controller, action: $action";
        }

        $data = $cache->get($key);

        if ($data) {
            $data['count']++;
        } else {
            $data = ['count' => 1, 'start' => time()];
        }

        if ($data['count'] > $numberOfRequests) {
            if ((time() - $data['start']) <= $this->getSecondsForInterval($interval)) {
                /**
                 * Block the request
                 */
                Craft::error("Rate limit of $numberOfRequests/$interval exceeded for IP: $ip, $identifier, method: $method", 'craft-rate-limiter');

                return true;
            } else {
                $data = ['count' => 1, 'start' => time()];
            }
        }

        $cache->set($key, $data, $this->getSecondsForInterval($interval));
        return false;
    }

    private function getSecondsForInterval(string $interval = 'minute'): int
    {
        return match($interval){
            'second' => 1,
            'hour' => 3600,
            default => 60,
        };
    }

    private function handleRateLimitResponse(): void
    {
        $response = Craft::$app->getResponse();
        $request = Craft::$app->getRequest();

        $message = Craft::t('craft-rate-limiter', 'Rate limit exceeded. Try again later.');

        if ($request->getIsAjax() || $request->getAcceptsJson()) {
            // JSON response for AJAX or API clients
            $response->format = Response::FORMAT_JSON;
            $response->statusCode = 429; // Too Many Requests
            $response->data = [
                'error' => $message,
            ];
        } else {
            // Standard HTTP response for browser requests
            $response->statusCode = 429; // Too Many Requests
            Craft::$app->getSession()->setFlash('error', $message);

            // Redirect back or to a specific page (e.g., login form)
            $response->redirect(Craft::$app->getRequest()->referrer ?: '/');
        }

        $response->send();
        Craft::$app->end();
    }

    private function dispatchEvent(string $requestMethod, ?string $controllerClass, ?string $actionId, ?string $urlPath, array $config, string $triggeredInterval): void
    {
        $this->trigger(self::RATE_LIMIT_EXCEEDED, new RateLimitExceededEvent([
            'requestMethod' => $requestMethod,
            'controllerClass' => $controllerClass,
            'actionId' => $actionId,
            'urlPath' => $urlPath,
            'triggeredInterval' => $triggeredInterval,
            'numberOfRequestsPerSecond' => $config['numberOfRequestsPerSecond'],
            'numberOfRequestsPerMinute' => $config['numberOfRequestsPerMinute'],
            'numberOfRequestsPerHour' => $config['numberOfRequestsPerHour'],
        ]));
    }

    private function matchesUrlPath(mixed $configPath, string $urlPath): bool
    {
        // Normalize both paths (remove leading slash for comparison)
        $normalizedConfigPath = ltrim($configPath, '/');
        $normalizedCurrentPath = ltrim($urlPath, '/');

        // Check for exact match first (optimization for simple paths)
        if ($normalizedConfigPath === $normalizedCurrentPath) {
            return true;
        }

        // Check if config path contains URL variables like {sku}
        if (str_contains($normalizedConfigPath, '{')) {
            // Convert URL pattern to regex
            // Escape special regex characters except for {}
            $pattern = preg_quote($normalizedConfigPath, '#');

            // Replace escaped curly braces and variable names with regex pattern
            // {variableName} becomes a pattern that matches any characters except /
            $pattern = preg_replace('#\\\{[^}]+\\\}#', '[^/]+', $pattern);

            // Match the full path
            $pattern = '#^' . $pattern . '$#';

            return (bool) preg_match($pattern, $normalizedCurrentPath);
        }

        return false;
    }
}
