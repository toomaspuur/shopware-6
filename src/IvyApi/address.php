<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\IvyApi;

class address
{
    private string $line1;

    private string $line2;

    private string $city;

    private string $zipCode;

    private string $country;

    private ?string $firstName = null;

    private ?string $lastName = null;

    public function setLine1(string $line1): address
    {
        $this->line1 = $line1;

        return $this;
    }

    public function setCity(string $city): address
    {
        $this->city = $city;

        return $this;
    }

    public function setZipCode(string $zipCode): address
    {
        $this->zipCode = $zipCode;

        return $this;
    }

    public function setCountry(string $country): address
    {
        $this->country = $country;

        return $this;
    }

    public function getLine1(): string
    {
        return $this->line1;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getZipCode(): string
    {
        return $this->zipCode;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setLine2(string $line2): address
    {
        $this->line2 = $line2;

        return $this;
    }

    public function getLine2(): string
    {
        return $this->line2;
    }

    /**
     * @return string|null
     */
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    /**
     * @param string|null $firstName
     * @return $this
     */
    public function setFirstName(?string $firstName): address
    {
        $this->firstName = $firstName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    /**
     * @param string|null $lastName
     * @return $this
     */
    public function setLastName(?string $lastName): address
    {
        $this->lastName = $lastName;

        return $this;
    }

}
