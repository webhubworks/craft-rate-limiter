<?php
/**
 * Rate Limiter plugin for Craft CMS 4.x & 5.x
 *
 * Integrate Rate Limiter into Craft CMS.
 *
 * @link      https://webhub.de
 * @copyright Copyright (c) 2025 webhub GmbH
 */

/**
 * Rate Limiter config.php
 *
 * This file exists only as a template for the Rate Limiter settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'craft-rate-limiter.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

//use webhubworks\craftratelimiter\models\RateLimiterConfig;

return [
    '*' => [
        //RateLimiterConfig::make()
            //->requestsPerMinute(2)
            //->requestMethods(['POST', 'PUT', 'PATCH', 'DELETE'])
            //->addControllerAction(
                //controllerClass: \craft\controllers\UsersController::class,
                //controllerActions: ['login']
            //)
            //->build(),
    ],
];