<?php
/*
 * Intune Authentication Demo
 *
 * Authenticates an intune-managed device using it's SSL certificate.
 */
declare(strict_types = 1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Dotenv\Dotenv;

require_once dirname (__DIR__) . '/vendor/autoload.php';

IntuneAuth::run();

class IntuneAuth {
    const LOG_NAME = 'IntuneAuth';
    const LOG_FILE = 'intune_auth.log';

    /**
     * @return AbstractAdapter
     */
    public static function getCache () {
        static $cache = null;
        if (!$cache) $cache = new FilesystemAdapter(
            // Cache things by default in the var directory for 15 minutes
            '',
            15 * 60,
            dirname (__DIR__) . '/var/cache/'
        );

        return $cache;
    }

    /**
     * @return Client
     */
    public function getHttpClient () {
        static $guzzle = null;
        if (!$guzzle) {
            $guzzle = new Client();
        }

        return $guzzle;
    }

    /**
     * @return Logger
     */
    public static function getLogger () {
        static $logger = null;
        if (!$logger) {
            // Create a file logger instance
            $logger = new Logger(self::LOG_NAME);
            $logger->pushHandler(new StreamHandler(dirname(__DIR__) . '/var/' . self::LOG_FILE), Logger::DEBUG);
        }

        return $logger;
    }

    public static function acquireApiToken () {
        $logger = self::getLogger();
        $client = self::getHttpClient();

        $logger->log(LogLevel::INFO,'Acquiring API token.');
        $url = sprintf ('https://login.microsoftonline.com/%s/oauth2/token?api-version=1.0', $_SERVER['TENANT_ID']);
        $logger->log(LogLevel::DEBUG,sprintf ('Acquisition URL: %s', $url));
        $token = json_decode($client->post($url, [
            'form_params' => [
                'client_id' => $_SERVER['CLIENT_ID'],
                'client_secret' => $_SERVER['CLIENT_SECRET'],
                'resource' => 'https://graph.microsoft.com/',
                'grant_type' => 'client_credentials',
            ],
        ])->getBody()->getContents());
        $accessToken = $token->access_token;
        $logger->log(LogLevel::DEBUG, sprintf ('Access Token: %s', $accessToken));
        return $accessToken;
    }

    public static function getApiToken () {
        $cache = self::getCache();
        $token = $cache->getItem('api_token');
        if (!$token->isHit()) {
            $token->expiresAfter(60 * 60);
            $token->set(self::acquireApiToken());
            $cache->save($token);
        };
        return $token->get();
    }

    /**
     * @param $deviceId
     * @return \Microsoft\Graph\Http\GraphResponse|mixed
     * @throws \Microsoft\Graph\Exception\GraphException
     */
    public static function getDeviceMetadata ($deviceId) {
        $logger = self::getLogger();
        $client = self::getHttpClient();
        $token = self::getApiToken();

        // Retrieve the metadata
        $graph = new Graph();
        $graph->setAccessToken($token);
        try {
            $deviceQuery = $graph->
            createRequest('GET', sprintf('/deviceManagement/managedDevices/%s', $deviceId))->
            setReturnType(Model\ManagedDevice::class);
            return $deviceQuery->execute();
        } catch (ClientException $exception) {
            exit (sprintf ("<pre>Fatal Error: %s</pre>", $exception->getResponse()->getBody()->getContents()));
        }
    }

    /**
     * Load configuration values, including API keys from .env.local
     */
    public static function loadConfig () {
        $dotenv = new Dotenv();
        $dotenv->loadEnv(dirname(__DIR__).'/.env');
    }



    public static function run() {
        $logger = self::getLogger();
        $logger->log(LogLevel::INFO,'Intune authenticator start.');

        $cache = self::getCache();
        $logger->log(LogLevel::DEBUG, 'Cache initialized');

        self::loadConfig();
        $logger->log(LogLevel::DEBUG, 'Configuration read');

        $token = self::getApiToken();

        // Ensure that SSL authentication has occurred
        if (!isset ($_SERVER['SSL_CLIENT_S_DN_CN']) ||
            !isset ($_SERVER['SSL_CLIENT_I_DN_CN']) ||
            'Microsoft Intune MDM Device CA' != $_SERVER['SSL_CLIENT_I_DN_CN']
        ) {
            exit ('Please ensure your server configuration is correct.');
        }

        // Attempt to retrieve metadata from the cache
        $deviceId = $_SERVER['SSL_CLIENT_S_DN_CN'];
        $metadata = $cache->getItem($deviceId);
        if (!$metadata->isHit()) {
            $metadata->expiresAfter(5*60);
            $metadata->set(self::getDeviceMetadata($deviceId));
        }
        $cache->save($metadata);

        // Print out the device properties
        echo '<h1>Device Information</h1><pre><table>';
        foreach ($metadata->get()->jsonSerialize() as $key => $value) {
            echo sprintf ('<tr><td>%s</td><td>%s</td></tr>', $key, $value);
        }
        echo '</pre></table>';
    }
}