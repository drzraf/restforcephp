<?php
namespace Jmondi\Restforce\RestClient;

use Jmondi\Restforce\Oauth\AccessToken;
use Jmondi\Restforce\Oauth\SalesforceProviderInterface;
use Jmondi\Restforce\Oauth\RetryAuthorizationTokenFailedException;
use Jmondi\Restforce\TokenRefreshInterface;
use Psr\Http\Message\ResponseInterface;

class SalesforceRestClient
{
    /**
     * @var RestClientInterface
     */
    private $restClient;
    /**
     * @var SalesforceProviderInterface
     */
    private $salesforceProvider;
    /**
     * @var AccessToken
     */
    private $accessToken;
    /**
     * @var string
     */
    private $resourceOwnerUrl;
    /**
     * @var TokenRefreshInterface|null
     */
    private $tokenRefreshObject;
    /**
     * @var int
     */
    private $maxRetryRequests;
    /**
     * @var string
     */
    private $apiVersion;

    /**
     * SalesforceRestClient constructor.
     * @param RestClientInterface $restClient
     * @param SalesforceProviderInterface $salesforceProvider
     * @param AccessToken $accessToken
     * @param string $resourceOwnerUrl
     * @param TokenRefreshInterface|null $tokenRefreshObject
     * @param string $apiVersion
     * @param int $maxRetryRequests
     */
    public function __construct(
        RestClientInterface $restClient,
        SalesforceProviderInterface $salesforceProvider,
        AccessToken $accessToken,
        string $resourceOwnerUrl,
        $tokenRefreshObject,
        string $apiVersion,
        int $maxRetryRequests
    ) {
        $this->restClient = $restClient;
        $this->salesforceProvider = $salesforceProvider;
        $this->accessToken = $accessToken;
        $this->resourceOwnerUrl = $resourceOwnerUrl;
        $this->maxRetryRequests = $maxRetryRequests;
        $this->apiVersion = $apiVersion;
        $this->tokenRefreshObject = $tokenRefreshObject;
    }

    public function request(string $method, string $uri = '', array $options = []):ResponseInterface
    {
        return $this->retryRequest(
            $method,
            $this->constructUrl($uri),
            $this->mergeOptions($options)
        );
    }

    public function getResourceOwnerUrl():string
    {
        return $this->resourceOwnerUrl;
    }

    private function getAccessToken():string
    {
        return $this->accessToken->getToken();
    }

    private function isResponseAuthorized(ResponseInterface $response):bool
    {
        return ! ($response->getStatusCode() === 401);
    }

    private function refreshAccessToken()
    {
        $refreshToken = $this->accessToken->getRefreshToken();

        $accessToken = $this->salesforceProvider->getAccessToken('refresh_token', [
            'refresh_token' => $refreshToken
        ]);

        if (!empty($this->tokenRefreshObject)) {
            $this->tokenRefreshObject->tokenRefreshCallback($accessToken);
        }

        $this->accessToken = $accessToken;
    }

    private function mergeOptions(array $options):array
    {
        $defaultOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken()
            ]
        ];
        $options = array_merge_recursive($defaultOptions, $options);
        return $options;
    }

    private function retryRequest(string $method, string $uri, array $options):ResponseInterface
    {
        $attempts = 0;
        do {
            $response = $this->restClient->request($method, $uri, $options);
            $isAuthorized = $this->isResponseAuthorized($response);

            if (!$isAuthorized) {
                $this->refreshAccessToken();
            }

            $attempts++;
        } while (!$isAuthorized && $attempts < $this->maxRetryRequests);

        if (!$isAuthorized) {
            throw new RetryAuthorizationTokenFailedException(
                'Max retry limit of ' . $this->maxRetryRequests . 'has been reached. oAuth Token Failed.'
            );
        }

        return $response;
    }

    private function constructUrl(string $endpoint):string
    {
        $beginsWithHttp = (substr($endpoint, 0, 7) === "http://") || (substr($endpoint, 0, 8) === "https://");

        if ($beginsWithHttp) {
            return $endpoint;
        }

        $baseUrl = $this->accessToken->getInstanceUrl() . '/services/data/' . $this->apiVersion . '/';
        return $baseUrl . $endpoint;
    }
}
