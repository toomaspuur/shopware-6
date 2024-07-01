<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\IvyPayment;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use WizmoGmbh\IvyPayment\IvyApi\address;
use WizmoGmbh\IvyPayment\IvyApi\lineItem;
use WizmoGmbh\IvyPayment\IvyApi\prefill;
use WizmoGmbh\IvyPayment\IvyApi\price;
use WizmoGmbh\IvyPayment\IvyApi\sessionCreate;
use WizmoGmbh\IvyPayment\IvyApi\shippingMethod;

class createIvyOrderData
{
    private EntityRepository $shippingRepository;

    private EntityRepository $mediaRepository;

    private Context $context;

    private EntityRepository $salesChannelRepo;

    public function __construct(
        EntityRepository $media,
        EntityRepository $shipping,
        EntityRepository $salesChannelRepo
    ) {
        $this->context = Context::createDefaultContext();
        $this->shippingRepository = $shipping;
        $this->mediaRepository = $media;
        $this->salesChannelRepo = $salesChannelRepo;
    }

    /**
     * @psalm-suppress PossiblyNullReference
     */
    public function getSessionCreateDataFromOrder(OrderEntity $order, array $config): sessionCreate
    {
        $ivyLineItems = $this->getLineItem($order);
        $billingAddress = $this->getBillingAddress($order);
        $price = $this->getPrice($order);
        $shippingMethod = $this->getShippingMethod($order);

        $data = new sessionCreate();
        $data->setPrice($price)
            ->setLineItems($ivyLineItems)
            ->addShippingMethod($shippingMethod)
            ->setBillingAddress($billingAddress)
            ->setCategory($config['IvyMcc'] ?? '')
            ->setReferenceId($order->getId())
            ->setHandshake(true);

        return $data;
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @param array $config
     * @param bool $isExpress
     * @param bool $skipShipping
     * @return sessionCreate
     */
    public function getIvySessionDataFromCart(
        Cart $cart,
        SalesChannelContext $context,
        array $config,
        bool $isExpress,
        bool $skipShipping = false
    ): sessionCreate
    {
        $cartPrice = $cart->getPrice();

        try {
            $shippingCosts = $cart->getShippingCosts();
            $shippingTotal = $shippingCosts->getTotalPrice() ?? 0;
            $shippingVat = $shippingCosts->getCalculatedTaxes()->first() == null ? 0 : $shippingCosts->getCalculatedTaxes()->first()->getTax();
        } catch (\Exception $e) {
            $shippingTotal = 0;
            $shippingVat = 0;
        }

        $shippingNet = $shippingTotal - $shippingVat;
        $subTotal = $cartPrice->getPositionPrice() ?? 0;
        $totalNet = $cartPrice->getNetPrice() ?? 0;

        if ($isExpress) {
            $total = $cartPrice->getTotalPrice() - $shippingTotal;
            $vat = $cartPrice->getCalculatedTaxes()->first()->getTax() - $shippingVat;
            $totalNet = $totalNet - $shippingNet;
            $shippingTotal = 0;
        } else {
            $total = $cartPrice->getTotalPrice();
            $vat = $cartPrice->getCalculatedTaxes()->first()->getTax();
        }

        $price = new price();

        $price
            ->setTotalNet($totalNet)
            ->setVat($vat)
            ->setTotal($total)
            ->setShipping($shippingTotal)
            ->setCurrency($context->getCurrency()->getIsoCode())
            ->setSubTotal($subTotal);

        $ivyLineItems = $this->getLineItemFromCart($cart);

        $ivySessionData = new sessionCreate();
        $ivySessionData->setExpress($isExpress);

        $ivySessionData
            ->setPrice($price)
            ->setLineItems($ivyLineItems);

        if ($isExpress) {
            $ivySessionData->setHandshake(null);
        } else {
            /** @var CustomerEntity $customer */
            $customer = $context->getCustomer();

            if ($customer) {
                $prefill = new prefill($customer->getEmail());
                $ivySessionData->setPrefill($prefill);
                $activeBillingAddress = $customer->getActiveBillingAddress();
                if ($activeBillingAddress) {
                    $billingAddress = new address();
                    $billingAddress
                        ->setLine1($activeBillingAddress->getStreet())
                        ->setCity($activeBillingAddress->getCity())
                        ->setZipCode($activeBillingAddress->getZipCode())
                        ->setCountry($activeBillingAddress->getCountry()->getIso())
                        ->setFirstName($activeBillingAddress->getFirstName())
                        ->setLastName($activeBillingAddress->getLastName());
                    $ivySessionData->setBillingAddress($billingAddress);
                }
            }

            $shippingMethod = $this->getShippingMethodFromCart($cart, $context);

            $ivySessionData
                ->setHandshake(true)
                ->addShippingMethod($shippingMethod);
        }

        return $ivySessionData;
    }

    /**
     * @psalm-suppress PossiblyNullReference
     * @psalm-suppress PossiblyNullIterator
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress DeprecatedMethod
     */
    private function getLineItem(OrderEntity $order): array
    {
        $ivyLineItems = [];
        /** @var OrderLineItemEntity $swLineItem */
        /** @phpstan-ignore-next-line */
        foreach ($order->getLineItems() as $swLineItem) {
            $lineItem = new lineItem();

            $tax = $swLineItem->getPrice()->getCalculatedTaxes()->first();
            if ($tax) {
                $netTotal = $tax->getPrice() - $tax->getTax();
                $netUnitPrice = $netTotal / $swLineItem->getPrice()->getQuantity();
                $vat = $tax->getTax() / $swLineItem->getPrice()->getQuantity();
            } else {
                $netUnitPrice = $swLineItem->getUnitPrice();
                $vat = 0.0;
            }

            $lineItem
                ->setName($swLineItem->getLabel())
                ->setReferenceId($swLineItem->getReferencedId())
                ->setSingleNet($netUnitPrice)
                ->setSingleVat($vat)
                ->setAmount($swLineItem->getTotalPrice())
                ->setQuantity($swLineItem->getQuantity())
                ->setImage($this->getProductImage($swLineItem));

            $ivyLineItems[] = $lineItem;
        }

        return $ivyLineItems;
    }

    private function getLineItemFromCart(Cart $cart): array
    {
        $ivyLineItems = [];

        /** @var \Shopware\Core\Checkout\Cart\LineItem\LineItem $swLineItem */
        foreach ($cart->getLineItems() as $swLineItem) {
            $lineItem = new lineItem();
            /** @var CalculatedPrice $calculatedPrice */
            $calculatedPrice = $swLineItem->getPrice();

            $tax = $calculatedPrice->getCalculatedTaxes()->first();
            if ($tax) {
                $netTotal = $tax->getPrice() - $tax->getTax();
                $netUnitPrice = $netTotal / $calculatedPrice->getQuantity();
                $vat = $tax->getTax() / $calculatedPrice->getQuantity();
            } else {
                $netUnitPrice = $calculatedPrice->getUnitPrice();
                $vat = 0;
            }

            $singleNet = $netUnitPrice;
            $singleVat = $vat;
            $quantity = $swLineItem->getQuantity();
            $lineItem->setName($swLineItem->getLabel())
                ->setReferenceId($swLineItem->getReferencedId())
                ->setSingleNet($netUnitPrice)
                ->setSingleVat($vat)
                ->setAmount(($singleNet + $singleVat) * $quantity)
                ->setQuantity($quantity);

            $ivyLineItems[] = $lineItem;
        }

        return $ivyLineItems;
    }


    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullReference
     * @psalm-suppress RedundantConditionGivenDocblockType
     */
    private function getProductImage(OrderLineItemEntity $swLineItem): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $swLineItem->getProductId()));

        $mediaSearchResult = $this->mediaRepository->search($criteria, $this->context);

        /** @var ProductMediaEntity $pme */
        $pme = $mediaSearchResult->first();
        if ($pme !== null && $pme->getMedia() !== null && $pme->getMedia()->getThumbnails() !== null && $pme->getMedia()->getThumbnails()->first() !== null) {
            $url = $pme->getMedia()->getThumbnails()->first()->getUrl();
        }

        return $url ?? '';
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullReference
     */
    private function getPrice(OrderEntity $order): price
    {
        $price = new price();
        $price->setTotalNet($order->getAmountNet())
            ->setShipping($order->getShippingTotal())
            ->setTotal($order->getAmountTotal())
            ->setCurrency($order->getCurrency()->getIsoCode());

        $calculatedTax = $order->getPrice()->getCalculatedTaxes()->first();
        if (!is_null($calculatedTax)) {
            $price->setVat($calculatedTax->getTax());
        }

        return $price;
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullReference
     */
    private function getShippingMethod(OrderEntity $order): shippingMethod
    {
        $shippingMethodId = $order->getDeliveries()->first()->getShippingMethodId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $shippingMethodId));
        $result = $this->shippingRepository->search($criteria, $this->context);
        /** @var ShippingMethodEntity $swShippingMethod */
        $swShippingMethod = $result->first();

        $shippingMethod = new shippingMethod();
        $shippingMethod
            ->setPrice($order->getShippingTotal())
            ->setName($swShippingMethod->getName())
            ->addCountries($order->getDeliveries()->getShippingAddress()->getCountries()->first()->getIso());

        return $shippingMethod;
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     * @return shippingMethod
     */
    public function getShippingMethodFromCart(Cart $cart, SalesChannelContext $salesChannelContext): shippingMethod
    {
        $criteria = new Criteria([$salesChannelContext->getSalesChannelId()]);
        $criteria->addAssociations(['countries', 'country']);
        /** @var SalesChannelEntity $salesChannelLoaded */
        $salesChannelLoaded = $this->salesChannelRepo->search($criteria, $salesChannelContext->getContext())->first();
        $country = $salesChannelLoaded->getCountry() ?? $salesChannelLoaded->getCountries()->first();
        $swShippingMethod = $cart->getDeliveries()->first()->getShippingMethod();
        $shippingMethod = new shippingMethod();
        $shippingMethod
            ->setPrice($cart->getShippingCosts()->getTotalPrice())
            ->setName($swShippingMethod->getName())
            ->addCountries($country->getIso());
        return $shippingMethod;
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullReference
     */
    private function getBillingAddress(OrderEntity $order): address
    {
        $billingAddress = new address();
        $billingAddress
            ->setLine1($order->getBillingAddress()->getStreet())
            ->setCity($order->getBillingAddress()->getCity())
            ->setZipCode($order->getBillingAddress()->getZipCode())
            ->setCountry($order->getBillingAddress()->getCountry()->getIso());

        return $billingAddress;
    }
}
