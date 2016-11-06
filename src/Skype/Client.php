<?php

namespace Skype;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Skype\Authentication\FileTokenStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Client
{
    /**
     * @var null|ClientInterface
     */
    public $client;
    /**
     * @var
     */
    private $token;
    /**
     * @var FileTokenStorage
     */
    private $tokenStorage;
    /**
     * @var
     */
    private $handlerStack;
    /**
     * @var null|LoggerInterface
     */
    private $logger;
    /**
     * @var null|OutputInterface
     */
    private $output;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var int
     */
    private $reAuthCounter = 0;

    /**
     * Client constructor.
     * @param array $config
     * @param OutputInterface|null $output
     * @param InputInterface|null $input
     * @param ClientInterface|null $client
     */
    public function __construct(array $config = [], LoggerInterface $logger = NULL, OutputInterface $output = NULL, ClientInterface $client = NULL)
    {
        $this->config = new Config($config);
        $this->tokenStorage = new FileTokenStorage($this->config->get('fileTokenStoragePath'));
        $this->logger = $logger;
        $this->output = $output;

        $this->initializeHttpClient($client);

    }

    /**
     * @param  ClientInterface|null $client
     * @return \GuzzleHttp\Client|ClientInterface
     */
    private function initializeHttpClient(ClientInterface $client = NULL)
    {
        if ($client) {
            return $this->client = $client;
        }

        return $this->client = $this->createDefaultHttpClient();
    }

    /**
     * @return \GuzzleHttp\Client
     */
    private function createDefaultHttpClient()
    {
        $this->handlerStack = HandlerStack::create(new CurlHandler());
        $this->handlerStack->push(Middleware::mapRequest($this->getReAuthMiddlewareClosure()), 'reAuth');

        $config = [
            'base_uri'                    => $this->config->get('baseUri'),
            'handler'                     => $this->handlerStack,
            'http_errors'                 => $this->config->get('httpErrors'),
            'curl.CURLOPT_PROXY'          => 'tcp://127.0.0.1:8888',
            'curl.CURLOPT_SSL_VERIFYPEER' => FALSE,
            'curl.CURLOPT_SSL_VERIFYHOST' => FALSE,
            'verify'                      => FALSE,
        ];

        return new \GuzzleHttp\Client($config);
    }

    /**
     * @return \Closure
     */
    private function getReAuthMiddlewareClosure()
    {
        return function (RequestInterface $request) {
            if ($this->reAuthCounter > 0) {
                $this->reAuthCounter = 0;

                return $request;
            }

            $now = new \DateTime('now', new \DateTimeZone('UTC'));

            if (
                ($this->tokenStorage->read('expires_in') < ($now->getTimestamp() + 600))
                && $this->config->get('clientId')
                && $this->config->get('clientSecret')
            ) {
                $this->log('<info>Trying to re-authenticate.</info>');

                ++$this->reAuthCounter;

                $this->auth();
                $this->authorize();

                $this->log('<info>Sending the request again with a token.</info>');

                return $request->withHeader(
                    'Authorization',
                    sprintf('Bearer %s', $this->token)
                );
            }

            if (
            ($this->tokenStorage->read('expires_in') < ($now->getTimestamp() + 600))
            ) {
                $this->log('<info>You should re-auth in the following 10 minutes.</info>');
            }

            return $request;
        };
    }

    private function log($message)
    {
        if ($this->output) {
            $this->output->writeln($message);
        }

        if ($this->logger) {
            $this->logger->info($message);
        }
    }

    /**
     * Authentication
     */
    public function auth()
    {
        $this->handlerStack->remove('reAuth');
        $res = $this->client->post($this->config->get('authUri'), [
            'form_params' => [
                'client_id'     => $this->config->get('clientId'),
                'client_secret' => $this->config->get('clientSecret'),
                'grant_type'    => 'client_credentials',
                'scope'         => 'https://graph.microsoft.com/.default'
            ]
        ]);

        $json = \GuzzleHttp\json_decode($res->getBody(), TRUE);

        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $json['expires_in'] = $now->getTimestamp() + $json['expires_in'];

        $this->tokenStorage->write($json);
    }

    /**
     * @param null $token
     */
    public function authorize($token = NULL)
    {
        if ($token) {
            $this->token = $token;
        } else {
            $this->token = $this->tokenStorage->read();
        }

        return $this;
    }

    /**
     * @param $name
     * @return mixed
     */
    public function api($name)
    {
        $class = 'Skype\\Api\\' . ucfirst($name);

        if (class_exists($class)) {
            return new $class($this->client, $this->token, $this->logger);
        } else {
            throw new \InvalidArgumentException('Unknown Api "' . $name . '" requested');
        }
    }
}
