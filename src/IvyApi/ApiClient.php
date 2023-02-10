<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\IvyApi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Monolog\Logger;
use WizmoGmbh\IvyPayment\Exception\IvyApiException;

class ApiClient
{
    private Logger $apiLogger;

    public function __construct(Logger $apiLogger)
    {
        $this->apiLogger = $apiLogger;
    }

    /**
     * @param string $endpoint
     * @param array $config
     * @param string $jsonContent
     * @return array
     * @throws IvyApiException
     */
    public function sendApiRequest(string $endpoint, array $config, string $jsonContent): array
    {
        $this->apiLogger->info('send ' . $endpoint . ' ' . $jsonContent);

        $client = new Client([
            'base_uri' => $config['IvyServiceUrl'],
            'headers' => [
                'X-Ivy-Api-Key' => $config['IvyApiKey'],
            ],
        ]);

        $headers['content-type'] = 'application/json';
        $options = [
            'headers' => $headers,
            RequestOptions::BODY => $jsonContent,
        ];

        try {
            $response = $client->post($endpoint, $options);
            $this->apiLogger->info('response: ' . (string)$response->getBody());
            if ($response->getStatusCode() === 200) {
                $response = \json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } else {
                $message = 'invalid response status: ' . $response->getStatusCode();
                $this->apiLogger->error($message);
                throw new IvyApiException($message);
            }
        } catch (\Exception | GuzzleException $e) {
            $this->apiLogger->error($e->getMessage());
            throw new IvyApiException($e->getMessage());
        }
        if (!\is_array($response)) {
            $message = 'invalid json response (is not array)';
            $this->apiLogger->error($message);
            throw new IvyApiException($message);
        }
        return $response;
    }
}
