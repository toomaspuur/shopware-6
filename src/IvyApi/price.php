<?php declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\IvyApi;

class price
{
    private float $totalNet = 0.0;

    private float $vat = 0.0;

    private float $shipping = 0.0;

    private float $total = 0.0;

    private string $currency;

    private float $subTotal = 0.0;

    public function setSubTotal(float $subTotal): price
    {
        $this->subTotal = $subTotal;

        return $this;
    }

    public function setTotalNet(float $totalNet): price
    {
        $this->totalNet = $totalNet;

        return $this;
    }

    public function setVat(float $vat): price
    {
        $this->vat = $vat;

        return $this;
    }

    public function setShipping(float $shipping): price
    {
        $this->shipping = $shipping;

        return $this;
    }

    public function setTotal(float $total): price
    {
        $this->total = $total;

        return $this;
    }

    public function setCurrency(string $currency): price
    {
        $this->currency = $currency;

        return $this;
    }

    public function getSubTotal(): float
    {
        return $this->subTotal;
    }

    public function getTotalNet(): float
    {
        return $this->totalNet;
    }

    public function getVat(): float
    {
        return $this->vat;
    }

    public function getShipping(): float
    {
        return $this->shipping;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
