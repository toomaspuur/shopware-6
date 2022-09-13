<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\IvyApi;

class lineItem
{
    private string $name;

    private string $referenceId;

    private float $singleNet;

    private float $singleVat;

    private float $amount;

    private string $image;

    private string $category;

    private string $ean;

    private float $quantity;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): lineItem
    {
        $this->name = $name;

        return $this;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function setReferenceId(string $referenceId): lineItem
    {
        $this->referenceId = $referenceId;

        return $this;
    }

    public function getSingleNet(): float
    {
        return $this->singleNet;
    }

    public function setSingleNet(float $singleNet): lineItem
    {
        $this->singleNet = $singleNet;

        return $this;
    }

    public function getSingleVat(): float
    {
        return $this->singleVat;
    }

    public function setSingleVat(float $singleVat): lineItem
    {
        $this->singleVat = $singleVat;

        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): lineItem
    {
        $this->amount = $amount;

        return $this;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function setImage(string $image): lineItem
    {
        $this->image = $image;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): lineItem
    {
        $this->category = $category;

        return $this;
    }

    public function getEan(): string
    {
        return $this->ean;
    }

    public function setEan(string $ean): lineItem
    {
        $this->ean = $ean;

        return $this;
    }

    /**
     * @return float
     */
    public function getQuantity(): float
    {
        return $this->quantity;
    }

    /**
     * @param float $quantity
     * @return $this
     */
    public function setQuantity(float $quantity): lineItem
    {
        $this->quantity = $quantity;
        return $this;
    }

}
