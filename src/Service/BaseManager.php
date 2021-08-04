<?php

declare(strict_types=1);

namespace Linio\SellerCenter\Service;

use Linio\SellerCenter\Application\Configuration;
use Linio\SellerCenter\Application\Parameters;
use Linio\SellerCenter\Application\Security\Signature;
use Linio\SellerCenter\Contract\ClientInterface;
use Linio\SellerCenter\Factory\RequestFactory;
use Linio\SellerCenter\Formatter\LogMessageFormatter;
use Linio\SellerCenter\Response\HandleResponse;
use Linio\SellerCenter\Response\SuccessResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

class BaseManager
{
    protected const DATE_TIME_FORMAT = 'Y-m-d\TH:i:s';
    protected const X_SOURCE_HEADER = 'X-Source';
    protected const REQUEST_ID_HEADER = 'Request-ID';

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var Parameters
     */
    protected $parameters;

    public function __construct(
        Configuration $configuration,
        ClientInterface $client,
        Parameters $parameters,
        LoggerInterface $logger
    ) {
        $this->configuration = $configuration;
        $this->client = $client;
        $this->parameters = $parameters;
        $this->logger = $logger;
    }

    public function makeParametersForAction(string $actionName): Parameters
    {
        $parameters = clone $this->parameters;
        $parameters->set([
            'Action' => $actionName,
        ]);

        return $parameters;
    }

    public function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param mixed[] $customHeaders
     *
     * @return mixed[]
     */
    protected function generateRequestHeaders(array $customHeaders = []): array
    {
        $headers = [
            self::REQUEST_ID_HEADER => $this->generateRequestId(),
            self::X_SOURCE_HEADER => $this->configuration->getSource(),
        ];
        if (empty($customHeaders)) {
            return $headers;
        }

        foreach ($customHeaders as $headerKey => $headerValue) {
            if (key_exists($headerKey, $headers)) {
                $headers[$headerKey] = $headerValue;
                unset($customHeaders[$headerKey]);
            }
        }

        return array_merge($customHeaders, $headers);
    }

    public function executeAction(
        string $action,
        Parameters $parameters,
        string $requestId,
        string $httpMethod = 'GET'
    ): SuccessResponse {
        $requestHeaders = $this->generateRequestHeaders([self::REQUEST_ID_HEADER => $requestId]);

        $request = RequestFactory::make($httpMethod, $this->configuration->getEndpoint(), $requestHeaders);

        $this->logRequest($action, $requestId, $request, $parameters);

        $response = $this->client->send($request, [
            'query' => $this->buildQuery($parameters),
        ]);

        $body = (string) $response->getBody();

        $this->logRawResponse($action, $requestId, $body);

        $builtResponse = HandleResponse::parse($body);

        $this->logHandledResponse($action, $requestId, $builtResponse);

        return $builtResponse;
    }

    /**
     * @return mixed[]
     */
    public function buildQuery(Parameters $parameters): array
    {
        return $parameters->all() + [
            'Signature' => Signature::generate(
                $parameters,
                $this->configuration->getKey()
            )->get(),
        ];
    }

    private function logRequest(
        string $action,
        string $requestId,
        RequestInterface $request,
        Parameters $parameters
    ): void {
        $this->logger->debug(
            LogMessageFormatter::fromAction($requestId, $action, LogMessageFormatter::TYPE_REQUEST),
            [
                'url' => (string) $request->getUri(),
                'method' => $request->getMethod(),
                'body' => (string) $request->getBody(),
                'parameters' => $parameters->all(),
            ]
        );
    }

    private function logRawResponse(string $action, string $requestId, string $body): void
    {
        $this->logger->debug(
            LogMessageFormatter::fromAction($requestId, $action, LogMessageFormatter::TYPE_RESPONSE),
            [
                'body' => $body,
            ]
        );
    }

    private function logHandledResponse(string $action, string $requestId, SuccessResponse $handledResponse): void
    {
        $this->logger->debug(
            LogMessageFormatter::fromAction($requestId, $action, LogMessageFormatter::TYPE_BUILT_RESPONSE),
            [
                'head' => $handledResponse->getHead()->asXML(),
                'body' => $handledResponse->getBody()->asXML(),
            ]
        );
    }
}
