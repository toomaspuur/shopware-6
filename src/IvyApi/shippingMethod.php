<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\IvyApi;

class shippingMethod
{
    private float $price;

    private string $name;

    private string $reference;

    private array $countries;

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): shippingMethod
    {
        $this->price = $price;

        return $this;
    }

    public function setName(string $name): shippingMethod
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCountries(): array
    {
        return $this->countries;
    }

    public function addCountries(string $country): shippingMethod
    {
        $this->countries[] = $country;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): shippingMethod
    {
        $this->reference = $reference;

        return $this;
    }
}
