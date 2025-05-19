<?php

namespace webhubworks\craftratelimiter;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use webhubworks\craftratelimiter\models\Settings;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Event;
use yii\base\Module;

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
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public array $config = [];
    public int $numberOfRequestsPerMinute = 0;

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

                $request = Craft::$app->getRequest();
                if($request->isConsoleRequest()){
                    return;
                }

                $controllerClass = get_class($event->action->controller); // e.g. 'craft\controllers\UsersController'
                $actionId = $event->action->id; // e.g. 'login'

                if (! in_array($request->getMethod(), $this->config['methods'])) {
                    return;
                }

                if (! in_array($controllerClass, array_keys($this->config['actions']))) {
                    return;
                }

                $controllerActions = $this->config['actions'][$controllerClass];
                if (
                    $controllerActions !== '*'
                    && (! is_array($controllerActions) || ! in_array($actionId, $controllerActions))
                ){
                    return;
                }

                $this->checkRateLimit(
                    method: $request->getMethod(),
                    controller: $controllerClass,
                    action: $actionId
                );
            }
        );
    }

    private function getConfig(): void
    {
        $this->config = Craft::$app->getConfig()->getConfigFromFile('craft-rate-limiter');

        if(isset($this->config['perMinute']) && $this->config['perMinute'] > 0) {
            $this->numberOfRequestsPerMinute = $this->config['perMinute'];
        }
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

    /**
     * TODO: Implement perSecond, perHour, etc.
     */
    private function checkRateLimit(
        string $method,
        string $controller,
        string $action
    ): void
    {
        /**
         * `Craft::$app->getRequest()->getUserIP()` should return the real IP address of the user
         * and not e.g. the IP of a load balancer or reverse proxy.
         */
        $ip = Craft::$app->getRequest()->getUserIP();
        $cache = Craft::$app->getCache();

        if (!$ip) {
            return;
        }

        $key = strtolower($method.'_'.$controller.'_'.$action.'_'.$ip);

        $data = $cache->get($key);

        if ($data) {
            $data['count']++;
        } else {
            $data = ['count' => 1, 'start' => time()];
        }

        if ($data['count'] > $this->numberOfRequestsPerMinute) {
            if ((time() - $data['start']) <= 60) {
                /**
                 * Block the request
                 */
                Craft::error("Rate limit of $this->numberOfRequestsPerMinute/min exceeded for IP: $ip, controller: $controller, action: $action, method: $method", 'craft-rate-limiter');

                Craft::$app->getResponse()->setStatusCode(429);
                Craft::$app->getResponse()->data = 'Rate limit exceeded. Try again later.';
                Craft::$app->end();
            } else {
                $data = ['count' => 1, 'start' => time()];
            }
        }

        $cache->set($key, $data, 60);
    }
}
