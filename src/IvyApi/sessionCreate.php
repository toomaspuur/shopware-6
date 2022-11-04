<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\IvyApi;

namespace WizmoGmbh\IvyPayment\IvyApi;

class sessionCreate
{
    private bool $express;

    private string $referenceId;

    private string $category;

    private price $price;

    private float $singleNet;

    private array $lineItems;

    private array $shippingMethods;

    private address $billingAddress;

    private string $verificationToken;

    private ?array $metadata;

    private string $plugin;

    private ?bool $handshake = false;

    private prefill $prefill;

    /**
     * @return bool
     */
    public function isExpress(): bool
    {
        return $this->express;
    }

    /**
     * @param bool $express
     * @return $this
     */
    public function setExpress(bool $express): sessionCreate
    {
        $this->express = $express;

        return $this;
    }


    public function setReferenceId(string $referenceId): sessionCreate
    {
        $this->referenceId = $referenceId;

        return $this;
    }

    public function setCategory(string $category): sessionCreate
    {
        $this->category = $category;

        return $this;
    }

    public function setPrice(price $price): sessionCreate
    {
        $this->price = $price;

        return $this;
    }

    public function setSingleNet(float $singleNet): sessionCreate
    {
        $this->singleNet = $singleNet;

        return $this;
    }

    public function addLineItem(lineItem $lineItem): sessionCreate
    {
        $this->lineItems[] = $lineItem;

        return $this;
    }

    public function setLineItems(array $lineItems): sessionCreate
    {
        $this->lineItems = $lineItems;

        return $this;
    }

    public function addShippingMethod(shippingMethod $shippingMethod): sessionCreate
    {
        $this->shippingMethods[] = $shippingMethod;

        return $this;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getPrice(): price
    {
        return $this->price;
    }

    public function getSingleNet(): float
    {
        return $this->singleNet;
    }

    public function getLineItems(): array
    {
        return $this->lineItems;
    }

    public function getShippingMethods(): array
    {
        return $this->shippingMethods;
    }

    public function getBillingAddress(): address
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(address $billingAddress): sessionCreate
    {
        $this->billingAddress = $billingAddress;

        return $this;
    }

    public function setVerificationToken(string $verificationToken): sessionCreate
    {
        $this->verificationToken = $verificationToken;

        return $this;
    }

    public function getVerificationToken(): string
    {
        return $this->verificationToken;
    }

    public function setMetadata(?array $metadata): sessionCreate
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setPlugin(string $plugin): sessionCreate
    {
        $this->plugin = $plugin;

        return $this;
    }

    public function getPlugin(): string
    {
        return $this->plugin;
    }

    /**
     * @return prefill
     */
    public function getPrefill(): prefill
    {
        return $this->prefill;
    }

    /**
     * @param prefill $prefill
     * @return $this
     */
    public function setPrefill(prefill $prefill): sessionCreate
    {
        $this->prefill = $prefill;

        return $this;
    }

    /**
     * @return bool
     */
    public function isHandshake(): bool
    {
        return (bool)$this->handshake;
    }

    /**
     * @param bool|null $handshake
     * @return void
     */
    public function setHandshake(?bool $handshake = null): sessionCreate
    {
        $this->handshake = $handshake;

        return $this;
    }

}
