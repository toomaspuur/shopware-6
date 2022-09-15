<?php

declare(strict_types=1);


namespace WizmoGmbh\IvyPayment\Components;

use Symfony\Component\HttpFoundation\JsonResponse;

class IvyJsonResponse extends JsonResponse
{
    /**
     * @param $data
     * @param int $status
     * @param array $headers
     * @param bool $json
     */
    public function __construct($data = null, int $status = 200, array $headers = [], bool $json = false)
    {
        $this->encodingOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        parent::__construct($data, $status, $headers, $json);
    }
}
