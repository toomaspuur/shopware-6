<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Express\Service;

use Doctrine\DBAL\Exception;
use Monolog\Logger;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Framework\Api\Controller\SalesChannelProxyController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannel\SalesChannelContextSwitcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Core\IvyPayment\createIvyOrderData;
use WizmoGmbh\IvyPayment\Exception\IvyException;
use WizmoGmbh\IvyPayment\PaymentHandler\IvyPaymentHandler;
use function hash_hmac;
use function json_decode;
use function print_r;
use function round;

class ExpressService
{
    private SystemConfigService $systemConfigService;

    private EntityRepositoryInterface $salesChannelRepo;

    private EntityRepositoryInterface $paymentRepository;

    private CartService $cartService;

    private ConfigHandler $configHandler;

    private RouterInterface $router;

    private createIvyOrderData $createIvyOrderData;

    private SalesChannelContextSwitcher $channelContextSwitcher;

    private SalesChannelContextServiceInterface $contextService;

    private PromotionItemBuilder $promotionItemBuilder;

    private Logger $logger;

    private SalesChannelRepositoryInterface $countryRepository;

    private AbstractShippingMethodRoute $shippingMethodRoute;

    private SalesChannelProxyController $salesChannelProxyController;

    private EntityRepositoryInterface $orderRepository;

    private string $version;

    private AbstractCartOrderRoute $orderRoute;


    /**
     * @param EntityRepositoryInterface $salesChannelRepo
     * @param EntityRepositoryInterface $paymentRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $pluginRepository
     * @param CartService $cartService
     * @param AbstractCartOrderRoute $orderRoute
     * @param SystemConfigService $systemConfigService
     * @param ConfigHandler $configHandler
     * @param RouterInterface $router
     * @param createIvyOrderData $createIvyOrderData
     * @param SalesChannelRepositoryInterface $countryRepository
     * @param SalesChannelContextSwitcher $channelContextSwitcher
     * @param SalesChannelContextServiceInterface $contextService
     * @param PromotionItemBuilder $promotionItemBuilder
     * @param AbstractShippingMethodRoute $shippingMethodRoute
     * @param SalesChannelProxyController $salesChannelProxyController
     * @param Logger $logger
     */
    public function __construct(
        EntityRepositoryInterface $salesChannelRepo,
        EntityRepositoryInterface $paymentRepository,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $pluginRepository,
        CartService $cartService,
        AbstractCartOrderRoute $orderRoute,
        SystemConfigService $systemConfigService,
        ConfigHandler $configHandler,
        RouterInterface $router,
        createIvyOrderData $createIvyOrderData,
        SalesChannelRepositoryInterface $countryRepository,
        SalesChannelContextSwitcher $channelContextSwitcher,
        SalesChannelContextServiceInterface $contextService,
        PromotionItemBuilder $promotionItemBuilder,
        AbstractShippingMethodRoute $shippingMethodRoute,
        SalesChannelProxyController $salesChannelProxyController,
        Logger $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->salesChannelRepo = $salesChannelRepo;
        $this->paymentRepository = $paymentRepository;
        $this->cartService = $cartService;
        $this->orderRoute = $orderRoute;
        $this->configHandler = $configHandler;
        $this->router = $router;
        $this->createIvyOrderData = $createIvyOrderData;
        $this->channelContextSwitcher = $channelContextSwitcher;
        $this->contextService = $contextService;
        $this->promotionItemBuilder = $promotionItemBuilder;
        $this->logger = $logger;
        $this->countryRepository = $countryRepository;
        $this->shippingMethodRoute = $shippingMethodRoute;
        $this->salesChannelProxyController = $salesChannelProxyController;
        $this->orderRepository = $orderRepository;
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'WizmoGmbhIvyPayment'));
        /** @var PluginEntity $plugin */
        $plugin = $pluginRepository->search($criteria, Context::createDefaultContext())->first();
        $this->version = $plugin->getVersion();
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return SalesChannelContext
     */
    public function switchPaymentMethod(SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        $this->logger->info('set ivy payment method');
        $switchData = [
            'paymentMethodId'  => $this->getPaymentMethodId(),
        ];
        try {
            $this->channelContextSwitcher->update(new DataBag($switchData), $salesChannelContext);
        } catch (\Exception $e) {
            $this->logger->warning('shipping not allowed. skip.');
        }
        return $this->reloadContext($salesChannelContext);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param $outputData
     * @return void
     * @throws Exception
     */
    public function getAllShippingVariants(SalesChannelContext $salesChannelContext, &$outputData): void
    {
        $this->logger->info('getAllShippingVariants for context token: ' . $salesChannelContext->getToken());
        $criteria = new Criteria([$salesChannelContext->getSalesChannelId()]);
        $criteria->addAssociations(['countries', 'country', 'shippingMethods']);
        /** @var SalesChannelEntity $salesChannelLoaded */
        $salesChannelLoaded = $this->salesChannelRepo->search($criteria, $salesChannelContext->getContext())->first();
        $countries = [];
        foreach ($salesChannelLoaded->getCountries() as $country) {
            $countries[] = $country->getIso();
        }
        $shippingMethods = [];

        $allowedShippingMethod = $this->getShippingMethods($salesChannelContext);
        /** @var ShippingMethodEntity $shippingMethod */
        foreach ($allowedShippingMethod as $shippingMethod) {
            $this->logger->info('check shipping method: ' . $shippingMethod->getName() . ' id: ' . $shippingMethod->getId());
            $switchData = [
                'countryId'        => $salesChannelLoaded->getCountry()->getId(),
                'paymentMethodId'  => $this->getPaymentMethodId(),
                'shippingMethodId' => $shippingMethod->getId()
            ];

            try {
                $this->channelContextSwitcher->update(new DataBag($switchData), $salesChannelContext);
            } catch (\Exception $e) {
                $this->logger->warning('shipping not allowed. skip.');
                continue;
            }
            $salesChannelContext = $this->reloadContext($salesChannelContext);

            $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
            $delivery = $cart->getDeliveries()->first();
            if ($delivery) {
                $shippingMethods[] = [
                    'price'     => round($delivery->getShippingCosts()->getTotalPrice(), 2),
                    'name'      => $shippingMethod->getName(),
                    'reference' => $shippingMethod->getId(),
                    'countries' => $countries,
                ];
            } else {
                $this->logger->info('not delivery found in cart. skip.');
            }
        }
        $this->logger->debug('allowed shippings: ' . print_r($shippingMethods, true));
        $outputData['shippingMethods'] = $shippingMethods;
    }

    /**
     * @param SalesChannelContext $context
     * @return ShippingMethodCollection
     */
    private function getShippingMethods(SalesChannelContext $context): ShippingMethodCollection
    {
        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        $shippingMethods = $this->shippingMethodRoute
            ->load($request, $context, new Criteria())
            ->getShippingMethods();

        if (!$shippingMethods->has($context->getShippingMethod()->getId())) {
            $shippingMethods->add($context->getShippingMethod());
        }
        return $shippingMethods->filterByActiveRules($context);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $contextToken
     * @return SalesChannelContext
     */
    public function reloadContext(SalesChannelContext $salesChannelContext, string $contextToken = null): SalesChannelContext
    {
        return $this->contextService->get(
            new SalesChannelContextServiceParameters(
                $salesChannelContext->getSalesChannelId(),
                $contextToken ?? $salesChannelContext->getToken(),
                $salesChannelContext->getLanguageId(),
                $salesChannelContext->getCurrencyId(),
                $salesChannelContext->getDomainId(),
            )
        );
    }

    public function getIvyOrderByReference(string $referenceId): ?OrderEntity
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        //TODO: add filter for payment method ivy and maybe paymentstatus
        $criteria->addFilter(new EqualsFilter('id', $referenceId))
            ->addAssociation('transactions.paymentMethod');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
        return $this->orderRepository->search($criteria, $context)->first();
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     * @throws Exception
     */
    public function isValidRequest(Request $request, SalesChannelContext $salesChannelContext): bool
    {
        $headers = $request->server->getHeaders();
        $hash = $this->sign($request->getContent(), $salesChannelContext);
        if (isset($headers['X-Ivy-Signature']) && $headers['X-Ivy-Signature'] === $hash) {
            return true;
        }
        return isset($headers['X_IVY_SIGNATURE']) && $headers['X_IVY_SIGNATURE'] === $hash;
    }

    /**
     * @param string $body
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws Exception
     */
    public function sign(string $body, SalesChannelContext $salesChannelContext): string
    {
        $config = $this->configHandler->getFullConfig($salesChannelContext);
        return hash_hmac(
            'sha256',
            $body,
            $config['IvyWebhookSecret']
        );
    }

    /**
     * @param array $data
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     * @throws Exception
     * @throws IvyException
     */
    public function updateUser(
        array $data,
        string $contextToken,
        SalesChannelContext $salesChannelContext
    ): bool
    {
        $this->logger->debug('try to update user');
        $userData = $this->prepareUserData($data, $salesChannelContext);

        $request = new Request([], []);
        $request->setMethod('POST');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy(
            'account/customer',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        $customerData = json_decode((string)$response->getContent(), true);
        if (isset($customerData['guest']) && $customerData['guest']) {
            $this->logger->info('already guest -> update');
            if (!empty($userData)) {
                $mainData = $userData;
                $billingAddress = $userData['billingAddress'];
                $shippingAddress = $userData['shippingAddress'];
                unset($mainData['billingAddress'], $mainData['shippingAddress'], $mainData['email']);

                $request = new Request([], $mainData);
                $request->setMethod('POST');
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
                $this->salesChannelProxyController->proxy(
                    'account/change-profile',
                    $salesChannelContext->getSalesChannelId(),
                    $request,
                    $salesChannelContext->getContext()
                );

                $request = new Request([], $billingAddress);
                $request->setMethod('PATCH');
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

                $this->salesChannelProxyController->proxy(
                    'account/address/' . $customerData['defaultBillingAddressId'],
                    $salesChannelContext->getSalesChannelId(),
                    $request,
                    $salesChannelContext->getContext()
                );

                $request = new Request([], $shippingAddress);
                $request->setMethod('PATCH');
                $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

                $this->salesChannelProxyController->proxy(
                    'account/address/' . $customerData['defaultShippingAddressId'],
                    $salesChannelContext->getSalesChannelId(),
                    $request,
                    $salesChannelContext->getContext()
                );

                $this->logger->info('guest data updated');
                return true;
            }
            $this->logger->info('can not update user from request (empty data)');
            return true;
        }
        $this->logger->info('guest not yet registered, can not update');
        return false;
    }

    /**
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws Exception
     * @throws IvyException
     */
    protected function prepareUserData(
        array $data,
        SalesChannelContext $salesChannelContext
    ): array
    {
        $billingAddress = $data['billingAddress'] ?? $data['shippingAddress'] ?? $data['shipping']['shippingAddress'] ?? [];
        $shippingAddress = $data['shippingAddress'] ?? $data['shipping']['shippingAddress'] ?? [];
        $this->logger->debug('received address: ' . print_r($billingAddress, true));
        if (\is_array($billingAddress) && !empty($billingAddress)) {
            $countryId = $this->getCountryIdFromAddress($billingAddress, $salesChannelContext);
            $shippingCountryId = $this->getCountryIdFromAddress($shippingAddress, $salesChannelContext);

            $config = $this->configHandler->getFullConfig($salesChannelContext);
            $salutationId = $config['defaultSalutation'];

            return [
                'guest'                  => true,
                'email'                  => $data['shopperEmail'] ?? '',
                'defaultPaymentMethodId' => $this->getPaymentMethodId(),
                'storefrontUrl'          => $this->getStorefrontUrl($salesChannelContext),
                'accountType'            => 'private',
                'salutationId'           => $salutationId,
                'firstName'              => $billingAddress['firstName'] ?? '',
                'lastName'               => $billingAddress['lastName'] ?? '',
                'billingAddress'         => [
                    'salutationId'           => $salutationId,
                    'firstName'              => $shippingAddress['firstName'] ?? '',
                    'lastName'               => $shippingAddress['lastName'] ?? '',
                    'zipcode'                => $billingAddress['zipCode'] ?? '',
                    'city'                   => $billingAddress['city'] ?? '',
                    'street'                 => $billingAddress['line1'] ?? '',
                    'additionalAddressLine1' => $billingAddress['line2'] ?? '',
                    'countryId'              => $countryId,
                    'phoneNumber'            => $data['shopperPhone'] ?? '',
                ],
                'shippingAddress'        => [
                    'salutationId'           => $salutationId,
                    'firstName'              => $shippingAddress['firstName'] ?? '',
                    'lastName'               => $shippingAddress['lastName'] ?? '',
                    'zipcode'                => $shippingAddress['zipCode'] ?? '',
                    'city'                   => $shippingAddress['city'] ?? '',
                    'street'                 => $shippingAddress['line1'] ?? '',
                    'additionalAddressLine1' => $shippingAddress['line2'] ?? '',
                    'countryId'              => $shippingCountryId,
                    'phoneNumber'            => $data['shopperPhone'] ?? '',
                ],
                'acceptedDataProtection' => true,
            ];
        }
        return [];
    }

    /**
     * @param array $data
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     * @throws Exception
     * @throws IvyException
     */
    public function createAndLoginQuickCustomer(
        array $data,
        string $contextToken,
        SalesChannelContext $salesChannelContext
    ): JsonResponse
    {
        $userData = $this->prepareUserData($data, $salesChannelContext);
        if (!empty($userData)) {
            $request = new Request([], $userData);
            $request->setMethod('POST');
            $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

            /** @var JsonResponse $response */
            $response = $this->salesChannelProxyController->proxy('account/register',
                $salesChannelContext->getSalesChannelId(),
                $request,
                $salesChannelContext->getContext()
            );
            return $response;
        }
        throw new IvyException('can not create user from request');
    }


    /**
     * @param RequestDataBag $data
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws IvyException
     */
    public function checkoutConfirm(
        RequestDataBag $data,
        string $contextToken,
        SalesChannelContext $salesChannelContext
    ): array
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        $order = $this->orderRoute->order($cart, $salesChannelContext, $data)->getOrder();

        $orderId = $order->getId();

        if (!$orderId) {
            throw new IvyException('order can not be created');
        }

        $this->logger->info('Initiate a payment for an order');
        $request = new Request([], [
            'orderId'      => $orderId,
            'paymentDetails' => [
                'confirmed' => true
            ]
        ]);
        $request->setMethod('POST');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('handle-payment',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        $paymentHandlerData = json_decode((string)$response->getContent(), true);
        $redirectUrl = \stripslashes($paymentHandlerData['redirectUrl'] ?? '');
        $this->logger->info('redirectUrl: ' . $redirectUrl);
        $paymentToken = $this->getToken($redirectUrl);
        $this->logger->info('paymentToken: ' . $paymentToken);

        $this->logger->info('update ivy order with new referenceId: ' . $order->getId());

        return [
            $order,
            $paymentToken
        ];
    }

     /**
     * @param string $orderId
     * @param SalesChannelContext $salesChannelContext
     * @return OrderEntity
     */
    public function getExpressOrder(string $orderId, SalesChannelContext $salesChannelContext): OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems.cover')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('billingAddress.salutation')
            ->addAssociation('billingAddress.country')
            ->addAssociation('billingAddress.countryState')
            ->addAssociation('deliveries.shippingOrderAddress.salutation')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('deliveries.shippingOrderAddress.countryState');

        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$order) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }

    /**
     * @param array $payload
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws IvyException
     */
    public function validateConfirmPayload(array $payload, string $contextToken, SalesChannelContext $salesChannelContext): void
    {
        $request = new Request();
        $request->setMethod('GET');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('checkout/cart',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );
        $cartData = json_decode((string)$response->getContent(), true);

        $cartPrice = $cartData['price'];

        $total = $cartPrice['totalPrice'];

        $violations = [];
        $accuracy = 0.0001;

        if (\abs($total - $payload['price']['total']) > $accuracy) {
            $violations[] = '$payload["price"]["total"] is ' . $payload['price']['total'] . ' waited ' . $total;
        }

        if ($salesChannelContext->getCurrency()->getIsoCode() !== $payload['price']['currency']) {
            $violations[] = '$payload["price"]["currency"] is ' . $payload['price']['currency'] . ' waited ' . $salesChannelContext->getCurrency()->getIsoCode();
        }

        $payloadLineItems = $payload['lineItems'] ?? [];
        if (empty($payloadLineItems) || !\is_array($payloadLineItems)) {
            $violations[] = 'checkout confirm with empty line items';
        }

        if (!empty($violations)) {
            throw new IvyException(print_r($violations, true));
        }
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function getStorefrontUrl(SalesChannelContext $salesChannelContext): string
    {
        $salesChannel = $salesChannelContext->getSalesChannel();
        $domainUrl = $this->systemConfigService->get('core.loginRegistration.doubleOptInDomain', $salesChannel->getId());

        if (\is_string($domainUrl) && $domainUrl !== '') {
            return $domainUrl;
        }

        $domains = $salesChannel->getDomains();
        if ($domains === null) {
            throw new SalesChannelDomainNotFoundException($salesChannel);
        }

        $domain = $domains->first();
        if ($domain === null) {
            throw new SalesChannelDomainNotFoundException($salesChannel);
        }

        return $domain->getUrl();
    }

    /**
     * @return string
     */
    public function getFinishUri(): string
    {
        return $this->router->generate('frontend.ivyexpress.finish', [], Router::ABSOLUTE_URL);
    }

    /**
     * @param array $payload
     * @param string $contextToken
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function setShippingMethod(array $payload, string $contextToken, SalesChannelContext $salesChannelContext): string
    {
        $shippingMethod = $payload['shippingMethod'];
        $this->logger->info('set shipping method:  ' . print_r($shippingMethod, true));

        $request = new Request([], [
            'paymentMethodId'  => $this->getPaymentMethodId(),
            'shippingMethodId' => $shippingMethod['reference'],
        ]);
        $request->setMethod('PATCH');
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

        /** @var JsonResponse $response */
        $response = $this->salesChannelProxyController->proxy('context',
            $salesChannelContext->getSalesChannelId(),
            $request,
            $salesChannelContext->getContext()
        );

        $contextData = json_decode((string)$response->getContent(), true);

        $newToken = $contextData['contextToken'];
        $this->logger->info('update context ' . $newToken);
        return $newToken;
    }

    /**
     * @param string $code
     * @param SalesChannelContext $salesChannelContext
     * @param $outputData
     * @return void
     * @throws Exception
     * @throws IvyException
     */
    public function addPromotion(string $code, SalesChannelContext $salesChannelContext, &$outputData): void
    {
        if ($code === '') {
            throw new IvyException('Discount code is required');
        }
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        try {
            // 1. here discount line item has no price yet
            $lineItem = $this->promotionItemBuilder->buildPlaceholderItem($code);
            $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
        } catch (\Exception $e) {
            throw new IvyException('Can not add discount: ' . $e->getMessage());
        }

        // 2. we need load validated and recalculated line item from cart, to obtain correct amount
        $loadedLineItem = null;
        /** @var LineItem $currentItem */
        foreach ($cart->getLineItems() as $currentItem) {
            if ($currentItem->isGood() ===  true) {
                continue;
            }
            if ($currentItem->getPayloadValue('code') === $code) {
                $loadedLineItem = $currentItem;
                break;
            }
        }

        if ($loadedLineItem === null) {
            // 3. discount was removed from cart by one cart-processor
            $this->logger->error('discount line-item with code ' . $code . ' not found in cart');
        }

        $config = $this->configHandler->getFullConfig($salesChannelContext);
        $ivyExpressSessionData = $this->createIvyOrderData->getIvySessionDataFromCart(
            $cart,
            $salesChannelContext,
            $config,
            true,
            true
        );

        if ($loadedLineItem && ($discountPrice = $loadedLineItem->getPrice()) !== null) {
            // 4. discount was successful added to cart
            $outputData['discount'] = [
                'amount' => -$discountPrice->getTotalPrice(),
            ];
        } else {
            $outputData['discount'] = [];
        }
        $price = $ivyExpressSessionData->getPrice();
        $outputData['price']['totalNet'] = $price->getTotalNet();
        $outputData['price']['vat'] = $price->getVat();
        $outputData['price']['total'] = $price->getTotal();
    }

    /**
     * @return string|null
     */
    public function getPaymentMethodId(): ?string
    {
        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', IvyPaymentHandler::class));
        return $this->paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
    }

    /**
     * @param array $address
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws IvyException
     */
    private function getCountryIdFromAddress(array $address, SalesChannelContext $salesChannelContext): string
    {
        $countryIso = $address['country'] ?? null;
        $countryId = null;
        if ($countryIso !== null) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', $countryIso));
            $countryId = $this->countryRepository->searchIds($criteria, $salesChannelContext)->firstId();
        }
        if ($countryId === null) {
            throw new IvyException('country with iso ' . $countryIso . ' not found');
        }
        return $countryId;
    }

    /**
     * @param string $returnUrl
     * @return string|null
     */
    private function getToken(string $returnUrl): ?string
    {
        $query = \parse_url($returnUrl, \PHP_URL_QUERY);
        \parse_str((string)$query, $params);

        return $params['_sw_payment_token'] ?? null;
    }

}
