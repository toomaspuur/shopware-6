<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\Checkout\Order;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class IvyPaymentSessionEntity extends Entity
{
    use EntityIdTrait;

    protected string $status;

    protected string $ivySessionId;

    protected ?string $swOrderId = null;

    protected string $ivyOrderId;

    protected string $ivyCo2Grams;

    protected ?array $expressTempData = [];

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getIvySessionId(): string
    {
        return $this->ivySessionId;
    }

    public function setIvySessionId(string $ivySessionId): void
    {
        $this->ivySessionId = $ivySessionId;
    }

    public function getSwOrderId(): ?string
    {
        return $this->swOrderId;
    }

    public function setSwOrderId(string $swOrderId): void
    {
        $this->swOrderId = $swOrderId;
    }

    public function getIvyOrderId(): string
    {
        return $this->ivyOrderId;
    }

    public function setIvyOrderId(string $ivyOrderId): void
    {
        $this->ivyOrderId = $ivyOrderId;
    }

    public function getIvyCo2Grams(): string
    {
        return $this->ivyCo2Grams;
    }

    public function setIvyCo2Grams(string $ivyCo2Grams): void
    {
        $this->ivyCo2Grams = $ivyCo2Grams;
    }

    /**
     * @return array
     */
    public function getExpressTempData(): array
    {
        return $this->expressTempData ?? [];
    }

    /**
     * @param array $expressTempData
     */
    public function setExpressTempData(array $expressTempData): void
    {
        $this->expressTempData = $expressTempData;
    }

}
