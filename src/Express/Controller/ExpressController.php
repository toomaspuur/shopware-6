<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Express\Controller;

use Shopware\Core\Checkout\Cart\Exception\InvalidCartException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Profiling\Profiler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedHook;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\ConstraintViolationInterface;
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;
use WizmoGmbh\IvyPayment\Components\IvyJsonResponse;
use WizmoGmbh\IvyPayment\Core\IvyPayment\IvyCheckoutSession;
use WizmoGmbh\IvyPayment\Exception\IvyException;
use WizmoGmbh\IvyPayment\Express\Service\ExpressService;
use WizmoGmbh\IvyPayment\Logger\IvyLogger;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 * @RouteScope(scopes={"storefront"})
 */
class ExpressController extends StorefrontController
{

    private ExpressService $expressService;

    private IvyLogger $logger;

    private ConfigHandler $configHandler;

    private GenericPageLoaderInterface $genericLoader;

    private array $errors = [];

    private IvyCheckoutSession $ivyCheckoutSession;

    private CartService $cartService;

    /**
     * @param ExpressService $expressService
     * @param ConfigHandler $configHandler
     * @param GenericPageLoaderInterface $genericLoader
     * @param IvyLogger $logger
     */
    public function __construct
    (
        ExpressService $expressService,
        ConfigHandler $configHandler,
        GenericPageLoaderInterface $genericLoader,
        IvyLogger $logger,
        IvyCheckoutSession $ivyCheckoutSession,
        CartService $cartService
    )
    {
        $this->expressService = $expressService;
        $this->configHandler = $configHandler;
        $this->logger = $logger;
        $this->genericLoader = $genericLoader;
        $this->ivyCheckoutSession = $ivyCheckoutSession;
        $this->cartService = $cartService;
    }

    /**
     * @Route("/ivycheckout/start", name="frontend.ivycheckout.start", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function checkoutStart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->_checkoutStart($request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN), $salesChannelContext, false);
    }

    /**
     * @Route("/ivyexpress/start", name="frontend.ivyexpress.start", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function expressStart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->_checkoutStart($request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN), $salesChannelContext, true);
    }

    public function _checkoutStart(string $contextToken, SalesChannelContext $salesChannelContext, bool $express): IvyJsonResponse
    {
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->info('-- create session -- express: ' . $express);
        $salesChannelContext = $this->expressService->switchPaymentMethod($salesChannelContext);
        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);
        $data = [];
        try {
            $redirectUrl = $this->ivyCheckoutSession->createCheckoutSession($contextToken, $salesChannelContext, $express, null, $cart);
            $this->logger->info('redirect to ' . $redirectUrl);
            $data['success'] = true;
            $data['redirectUrl'] = $redirectUrl;
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $this->logger->error($message);
            $this->addFlash(self::DANGER, $this->trans('ivypaymentplugin.express.session.error'));
            $data['success'] = false;
            $data['error'] = $message;
        }
        \ini_set('serialize_precision', '-1');
        return new IvyJsonResponse($data);
    }

    /**
     * @Route("/ivyexpress/callback", name="frontend.ivyexpress.callback", methods={"POST", "GET"},
     *     defaults={
     *          "XmlHttpRequest"=true,
     *          "csrf_protected"=false,
     *          "auth_required"=false,
     *     })
     */
    public function callback(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));

        $this->logger->info('received ivy callback: ' . print_r($inputData->all(), true));

        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);
        $this->logger->debug('signature ' . ($isValid ? 'valid' : 'not valid'));

        $outputData = [];
        $errorStatus = null;
        try {
            if ($isValid === true) {
                $payload = $data = $inputData->all();
                $referenceId = $request->get('reference');
                $this->logger->info('callback reference: ' . $referenceId);

                $shipping = $data['shipping'] ?? null;
                try {
                    $contextToken = $payload['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN];
                    $this->logger->info('found context token ' . $contextToken);
                    $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                    $this->logger->info(
                        'loaded context with token : ' . $salesChannelContext->getToken(
                        ) . ' customerId: ' . $this->getCustomerIdFromContext($salesChannelContext)
                    );

                    if (\is_array($shipping) && isset($shipping['shippingAddress'])) {
                        $updated = $this->expressService->updateUser($payload, $contextToken, $salesChannelContext);
                        if (!$updated) {
                            $this->logger->debug('not updated, try to create new guest and login');
                            $storeApiResponse = $this->expressService->createAndLoginQuickCustomer(
                                $payload,
                                $contextToken,
                                $salesChannelContext
                            );
                            $customerData = \json_decode((string) $storeApiResponse->getContent(), true);
                            if ((string) ($customerData['email'] ?? '') === '') {
                                $message = 'can not create customer. Status code: ' . $storeApiResponse->getStatusCode(
                                ) . ' body: ' . $storeApiResponse->getContent();
                                throw new IvyException($message);
                            }
                            $this->logger->info('created customer: ' . $customerData['email']);
                            $contextToken = $storeApiResponse->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
                            $this->logger->info('new context token: ' . $contextToken);
                            $salesChannelContext = $this->expressService->reloadContext(
                                $salesChannelContext,
                                $contextToken
                            );
                            $this->logger->info(
                                'loaded new context. Token: ' . $salesChannelContext->getToken(
                                ) . ', customerId: ' . $this->getCustomerIdFromContext($salesChannelContext)
                            );

                            $this->logger->info('save new context token');
                            $outputData['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN] = $contextToken;
                        }
                    }
                } catch (\Exception $e) {
                    $errorStatus = $this->handleException($e);
                }

                if (!isset($contextToken)) {
                    throw new IvyException('can not obtain $contextToken');
                }
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);

                $customer = $salesChannelContext->getCustomer();
                $activeShipping = $customer ? $customer->getActiveShippingAddress() : null;
                $activeShippingCountry = $activeShipping ? $activeShipping->getCountry() : null;
                if ($activeShippingCountry) {
                    $this->logger->debug('active shipping country: ' . $activeShippingCountry->getName());
                } else {
                    $this->logger->debug('not shipping country found in context');
                }
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);

                if (\is_array($shipping) && isset($shipping['shippingAddress'])) {
                    try {
                        $this->expressService->getAllShippingVariants($salesChannelContext, $outputData);
                    } catch (\Exception $e) {
                        $error = 'shipping callback error: ' . $e->getMessage();
                        $this->errors[] = $error;
                        $this->logger->error($error);
                        $outputData['shippingMethods'] = [];
                    }
                }

                $discount = $data['discount'] ?? null;
                if (\is_array($discount) && isset($discount['voucher'])) {
                    $voucherCode = (string) $discount['voucher'];
                    try {
                        $this->expressService->addPromotion($voucherCode, $salesChannelContext, $outputData);
                    } catch (\Exception $e) {
                        $error = 'discount callback error: ' . $e->getMessage();
                        $this->errors[] = $error;
                        $this->logger->error($error);
                        $outputData['discount'] = [];
                    }
                }
            } else {
                $this->errors[] = 'not valid signature';
                $errorStatus = Response::HTTP_FORBIDDEN;
            }
        } catch (\Throwable $e) {
            $errorStatus = $this->handleException($e);
        }

        if ($errorStatus !== null) {
            $outputData['shippingMethods'] = [];
            $outputData['discount'] = [];
        }

        if (!empty($this->errors)) {
            $outputData['errors'] = $this->errors;
        }

        \ini_set('serialize_precision', '-1');
        $response = new IvyJsonResponse($outputData);
        $signature = $this->expressService->sign((string) $response->getContent(), $salesChannelContext);

        $response->headers->set('X-Ivy-Signature', $signature);
        $this->logger->info('output body:' . $response->getContent());
        $this->logger->info('X-Ivy-Signature:' . $signature);
        return $response;
    }


    /**
     * @Route("/ivyexpress/confirm", name="frontend.ivyexpress.confirm", methods={"POST", "GET"},
     *     defaults={
     *          "XmlHttpRequest"=true,
     *          "csrf_protected"=false,
     *          "auth_required"=false,
     *     })
     */
    public function confirmAction(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext): Response
    {
        $errorStatus = null;
        $outputData = [];
        $finishUrl = $this->expressService->getFinishUri();
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->debug('confirm action (finish url: ' . $finishUrl . ')');
        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);
        $this->logger->debug('signature ' . ($isValid ? 'valid' : 'not valid'));

        if ($isValid === true) {
            try {
                $payload = $inputData->all();
                $this->logger->debug('confirm payload: ' . var_export($payload, true));
                if (empty($payload)) {
                    throw new IvyException('empty payload');
                }

                $this->logger->info('confirm payload is valid, start create order');

                $referenceId = $payload['referenceId'] ?? null;

                //always prefer an existing order
                $existingOrder = $this->expressService->getIvyOrderByReference($referenceId);
                if ($existingOrder) {
                    $this->logger->info('order existing');
                    $response = new IvyJsonResponse([
                        'redirectUrl' => $finishUrl,
                        'referenceId' => $referenceId,
                        'displayId' => $existingOrder->getOrderNumber(),
                        'metadata' => $payload['metadata'],
                    ]);
                    $signature = $this->expressService->sign(\stripslashes((string) $response->getContent()), $salesChannelContext);
                    $response->headers->set('X-Ivy-Signature', $signature);
                    return $response;
                }

                $isExpress = $payload['express'];
                $this->logger->info('express: ' . \var_export($isExpress, true));

                $contextToken = $payload['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN];
                $this->logger->info('found context token ' . $contextToken);
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                $this->logger->info('loaded context with token : ' . $salesChannelContext->getToken() . ' customerId: ' . $this->getCustomerIdFromContext($salesChannelContext));

                if ($isExpress) {
                    $this->expressService->updateUser($payload, $contextToken, $salesChannelContext);
                    $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                }

                $this->expressService->validateConfirmPayload($payload, $contextToken, $salesChannelContext);

                if ($isExpress) {
                    $payload['shopperEmail'] = $tempData['shopperEmail'] ?? '';
                    $payload['shopperPhone'] = $tempData['shopperPhone'] ?? '';
                    $this->logger->debug('shopperEmail: ' . $payload['shopperEmail']);
                    $this->logger->debug('shopperPhone: ' . $payload['shopperPhone']);
                }

                $orderData = $this->expressService->checkoutConfirm(
                    $referenceId,
                    $contextToken,
                    $salesChannelContext
                );
                $outputData = [
                    'redirectUrl' => $finishUrl,
                    'displayId' => $orderData['orderNumber'],
                    'referenceId' => $orderData['id'],
                    'metadata' => [
                        '_sw_payment_token' => $orderData['_sw_payment_token'],
                    ]
                ];
            } catch (\Throwable $e) {
                $errorStatus = $this->handleException($e);
            }
        } else {
            $errorStatus = Response::HTTP_FORBIDDEN;
        }

        if (!empty($this->errors)) {
            $outputData['errors'] = $this->errors;
        }

        \ini_set('serialize_precision', '-1');
        $response = new IvyJsonResponse($outputData);
        $signature = $this->expressService->sign(\stripslashes((string) $response->getContent()), $salesChannelContext);
        if ($errorStatus !== null) {
            $response->setStatusCode($errorStatus);
        }
        $response->headers->set('X-Ivy-Signature', $signature);
        $this->logger->info('output body:' . $response->getContent());
        $this->logger->info('X-Ivy-Signature:' . $signature);
        return $response;
    }

    /**
     * @Route("/ivyexpress/finish", name="frontend.ivyexpress.finish", methods={"GET"})
     */
    public function finishAction(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext)
    {
        try {
            $referenceId = $request->get('reference');
            $ivyOrderId = $request->get('order-id');
            $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
            $this->logger->debug('finish action reference: ' . $referenceId);
            $this->logger->debug('order-id ' . $ivyOrderId);

            $existingOrder = $this->expressService->getIvyOrderByReference($referenceId);

            $swOrder = $this->expressService->getExpressOrder($existingOrder->getId(), $salesChannelContext);

            $page = $this->genericLoader->load($request, $salesChannelContext);
            $page = CheckoutFinishPage::createFrom($page);
            if ($page->getMetaInformation()) {
                $page->getMetaInformation()->setRobots('noindex,follow');
            }

            if (\class_exists(Profiler::class)) {
                Profiler::trace(
                    'finish-page-order-loading',
                    static function () use ($page, $swOrder): void {
                        $page->setOrder($swOrder);
                    }
                );
            } else {
                $page->setOrder($swOrder);
            }

            $page->setChangedPayment(false);
            $page->setPaymentFailed(false);

            if ($page->getOrder()->getItemRounding()) {
                $salesChannelContext->setItemRounding($page->getOrder()->getItemRounding());
                $salesChannelContext->getContext()->setRounding($page->getOrder()->getItemRounding());
            }
            if ($page->getOrder()->getTotalRounding()) {
                $salesChannelContext->setTotalRounding($page->getOrder()->getTotalRounding());
            }
            if (\method_exists($this, 'hook')) {
                $this->hook(new CheckoutFinishPageLoadedHook($page, $salesChannelContext));
            }
            return $this->renderStorefront(
                '@Storefront/storefront/page/checkout/finish/index.html.twig',
                ['page' => $page]
            );
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->addFlash(self::DANGER, $this->trans('ivypaymentplugin.express.checkout.error'));
            return $this->redirectToRoute('frontend.checkout.finish.page');
        }
    }

    /**
     * @param \Throwable $e
     * @return int
     */
    private function handleException(\Throwable $e): int
    {
        $errorStatus = Response::HTTP_INTERNAL_SERVER_ERROR;
        if ($e instanceof IvyException) {
            $errorStatus = Response::HTTP_BAD_REQUEST;
        } elseif ($e instanceof ConstraintViolationException) {
            $errorStatus = Response::HTTP_BAD_REQUEST;
            /** @var ConstraintViolationInterface $violation */
            foreach ($e->getViolations() as $violation) {
                $this->logger->error($violation->getMessage());
            }
        } elseif ($e instanceof InvalidCartException) {
            $errorStatus = Response::HTTP_BAD_REQUEST;
            $this->logger->error('can not create create order, cart is invalid');
            foreach ($e->getErrors() as $error) {
                $this->logger->error(\print_r($error, true));
            }
        }
        $this->logger->error($e->getMessage());
        $this->errors[] = $e->getMessage() . ' ' . $e->getTraceAsString();
        return $errorStatus;
    }

    /**
     * @param SalesChannelContext $channelContext
     * @return string|null
     */
    private function getCustomerIdFromContext(SalesChannelContext $channelContext): ?string
    {
        if (\method_exists($channelContext, 'getCustomerId')) {
            return $channelContext->getCustomerId();
        }
        if (\method_exists($channelContext, 'getCustomer')) {
            $customer = $channelContext->getCustomer();
            if ($customer !== null) {
                return $customer->getId();
            }
        }
        return null;
    }
}