<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Controller\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;

/**
 * @Route(defaults={"_routeScope"={"administration"}})
 */
class ApiTestController
{
    /**
     * @Route(path="/api/_action/ivy-api-test/verify")
     */
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        $env = $dataBag->get('environment');

        switch ($env) {
            case 'Production':
                $url = ConfigHandler::PROD_API_URL;
                break;
            case 'Sandbox':
                $url = ConfigHandler::SAND_API_URL;
                break;
            default:
                return new JsonResponse(['success' => false]);
        }
        $apikey = $dataBag->get('WizmoGmbhIvyPayment.config.' . $env . 'IvyApiKey', '');
        $data = $this->checkIntegrationReady($url, $apikey);
        return new JsonResponse($data);
    }

    /**
     * @param string $url
     * @param string $apikey
     * @return array
     */
    private function checkIntegrationReady(string $url, string $apikey): array
    {
        $response['success'] = false;

        $client = new Client([
            'base_uri' => $url,
            'headers' => [
                'X-Ivy-Api-Key' => $apikey,
            ],
        ]);

        $headers['content-type'] = 'application/json';
        $options = [
            'headers' => $headers,
            'body' => '',
        ];

        try {
            $ivyResponse = $client->post('merchant/app/check-integration-ready', $options);

            if ($ivyResponse->getStatusCode() === 200) {
                $ivyResponseArray = \json_decode((string) $ivyResponse->getBody(), true);

                $response['success'] = $ivyResponseArray['ready'];
                $response['message'] = '';
                if ($ivyResponseArray['ready'] === false) {
                    $response['message'] = \implode(', ', $ivyResponseArray['missingFields'] ?? []);
                }
            }
        } catch (GuzzleException $e) {
            $response['success'] = false;
            $response['message'] = '';
            \error_log($e->getMessage());
        }

        return $response;
    }
}
