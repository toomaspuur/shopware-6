<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Express\Controller;

use Shopware\Core\Checkout\Cart\Exception\InvalidCartException;
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
use WizmoGmbh\IvyPayment\Core\Checkout\Order\IvyPaymentSessionEntity;
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
        IvyLogger $logger
    )
    {
        $this->expressService = $expressService;
        $this->configHandler = $configHandler;
        $this->logger = $logger;
        $this->genericLoader = $genericLoader;
    }

    /**
     * @Route("/ivycheckout/start", name="frontend.ivycheckout.start", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function checkoutStart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->info('-- create new normal session');
        $data = [];
        try {
            $redirectUrl = $this->expressService->createNormalSession($request, $salesChannelContext);
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
        \ini_set('serialize_precision', '3');
        return new IvyJsonResponse($data);
    }

    /**
     * @Route("/ivyexpress/start", name="frontend.ivyexpress.start", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function expressStart(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->setLevel($this->configHandler->getLogLevel($salesChannelContext));
        $this->logger->info('-- create new express session');
        $data = [];
        try {
            $redirectUrl = $this->expressService->createExpressSession($request, $salesChannelContext);
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
        \ini_set('serialize_precision', '3');
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
        /*
         [2022-07-22T17:28:47.975375+00:00] ivypayment/express.INFO: !!!!!!!!!reseived ivy callback:
        Array (
            [shopperEmail] => test@test.de,
            [appId] => 62d3ea101eca1e3554e85a92
            [shipping] => Array (
                [shippingAddress] => Array (
                    [country] => DE
                    [zipCode] => esdtseqwe
                    [city] => sdgsdgeqwefdsf
                    [line2] => sdgdsgsdewqefsdf //additi
                    [line1] => testewqe //street, nr
                    [lastName] => ewqewqfsfsd
                    [firstName] => testwwqefsaf
                )
            )
            [currency] => EUR
        )
        */

        $this->logger->info('reseived ivy callback: ' . \print_r($inputData->all(), true));

        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);
        $this->logger->debug('signatur ' . ($isValid ? 'valid' : 'not valid'));

        $outputData = [];
        $errorStatus = null;

        if ($isValid === true) {
            $payload = $data = $inputData->all();
            $referenceId = $request->get('reference');
            $this->logger->info('callback reference: ' . $referenceId);
            /** @var IvyPaymentSessionEntity $ivyPaymentSession */
            $ivyPaymentSession = $this->expressService->getIvySessionByReference($referenceId);

            try {
                if ($ivyPaymentSession === null) {
                    throw new IvyException('ivy transaction by reference ' . $referenceId . ' not found');
                }
                $tempData = $ivyPaymentSession->getExpressTempData();
                if (\is_array($data)) {
                    $tempData = \array_merge($tempData, $data);
                }

                $contextToken = $tempData[PlatformRequest::HEADER_CONTEXT_TOKEN];
                $this->logger->info('found context token ' . $contextToken);
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                $this->logger->info('loaded context with token : ' . $salesChannelContext->getToken() . ' customerId: ' . $this->getCustomerIdFromContext($salesChannelContext));

                $updated =  $this->expressService->updateUser( $payload, $contextToken, $salesChannelContext);
                if (!$updated) {
                    $this->logger->debug('not updated, try to create new guest and login');
                    $storeApiResponse = $this->expressService->createAndLoginQuickCustomer(
                        $payload,
                        $contextToken,
                        $salesChannelContext
                    );
                    $customerData = \json_decode((string)$storeApiResponse->getContent(), true);
                    if ((string)($customerData['email'] ?? '') === '') {
                        $message = 'cann not create customer. Status code: ' . $storeApiResponse->getStatusCode() . ' body: ' . $storeApiResponse->getContent();
                        throw new IvyException($message);
                    }
                    $this->logger->info('created customer: ' .  $customerData['email']);
                    $contextToken = $storeApiResponse->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
                    $this->logger->info('new context token: ' .  $contextToken);
                    $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                    $this->logger->info('loaded new context. Token: ' . $salesChannelContext->getToken() . ', customerId: ' . $this->getCustomerIdFromContext($salesChannelContext));
                    $this->logger->info('save new context token');
                    $tempData[PlatformRequest::HEADER_CONTEXT_TOKEN] = $contextToken;
                    $ivyPaymentSession->setExpressTempData($tempData);
                }
            } catch (\Exception $e) {
                $errorStatus = $this->handleException($e);
            }

            $this->expressService->flushTempData($ivyPaymentSession, $salesChannelContext);
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

            $shipping = $data['shipping'] ?? null;
            if (\is_array($shipping) && isset($shipping['shippingAddress'])) {
                try {
                    $this->expressService->getAllShippingVariants($salesChannelContext, $outputData);
                } catch (\Exception $e) {
                    $this->logger->error('shipping callback error: ' . $e->getMessage());
                    $outputData['shippingMethods'] = [];
                }
            }

            $discount = $data['discount'] ?? null;
            if (\is_array($discount) && isset($discount['voucher'])) {
                $voucherCode = (string)$discount['voucher'];
                try {
                    $this->expressService->addPromotion($voucherCode, $salesChannelContext, $outputData);
                } catch (\Exception $e) {
                    $this->logger->error('discount callback error: ' . $e->getMessage());
                    $outputData['discount'] = [];
                }
            }
        } else {
            $errorStatus = Response::HTTP_FORBIDDEN;
        }

        if ($errorStatus !== null) {
            $outputData['shippingMethods'] = [];
            $outputData['discount'] = [];
        }

        \ini_set('serialize_precision', '3');
        $response = new IvyJsonResponse($outputData);
        $signature = $this->expressService->sign((string)$response->getContent(), $salesChannelContext);

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
        $this->logger->debug('signatur ' . ($isValid ? 'valid' : 'not valid'));

        if ($isValid === true) {
            try {
                $payload = $inputData->all();
                $this->logger->debug('confirm payload: ' . var_export($payload, true));
                if (empty($payload)) {
                    throw new IvyException('empty payload');
                }
                $contextToken = $payload['metadata'][PlatformRequest::HEADER_CONTEXT_TOKEN] ?? null;
                if (empty($contextToken)) {
                    throw new IvyException(PlatformRequest::HEADER_CONTEXT_TOKEN . ' not provided');
                }

                $this->logger->info('confirm payload is valid, start create order');

                $referenceId = $payload['referenceId'] ?? null;

                $ivyPaymentSession = $this->expressService->getIvySessionByReference($referenceId);
                if ($ivyPaymentSession === null) {
                    throw new IvyException('ivy session not found by refenceId ' . $referenceId);
                }
                $this->logger->debug('loaded ivy session data from db');
                $tempData = $ivyPaymentSession->getExpressTempData();

                $isExpress = $tempData['express'] ?? true;
                $this->logger->info('express: ' . \var_export($isExpress, true));

                $contextToken = $tempData[PlatformRequest::HEADER_CONTEXT_TOKEN];
                $this->logger->info('found context token ' . $contextToken);
                $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                $this->logger->info('loaded context with token : ' . $salesChannelContext->getToken() . ' customerId: ' . $this->getCustomerIdFromContext($salesChannelContext));

                if ($isExpress) {
                    $contextToken = $this->expressService->setShippingMethod($payload, $contextToken, $salesChannelContext);
                    $this->logger->info('new context token: ' .  $contextToken);

                    $this->expressService->updateUser($payload, $contextToken,$salesChannelContext);
                    $salesChannelContext = $this->expressService->reloadContext($salesChannelContext, $contextToken);
                }

                $this->expressService->validateConfirmPayload($payload, $contextToken, $salesChannelContext);

                if ($isExpress) {
                    $payload['shopperEmail'] = $tempData['shopperEmail'];
                    $payload['shopperPhone'] = $tempData['shopperPhone'];
                    $this->logger->debug('shopperEmail: ' . $payload['shopperEmail']);
                    $this->logger->debug('shopperPhone: ' . $payload['shopperPhone']);
                }

                $tempData[PlatformRequest::HEADER_CONTEXT_TOKEN] = $contextToken;
                $ivyPaymentSession->setExpressTempData($tempData);
                $this->expressService->flushTempData($ivyPaymentSession, $salesChannelContext);

                $orderData = $this->expressService->checkoutConfirm(
                    $ivyPaymentSession,
                    $payload,
                    $contextToken,
                    $salesChannelContext
                );
                $outputData = [
                    'redirectUrl' => $finishUrl,
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

        \ini_set('serialize_precision', '3');
        $response = new IvyJsonResponse($outputData);
        $signature = $this->expressService->sign(\stripslashes((string)$response->getContent()), $salesChannelContext);
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
            $ivyPaymentSession = $this->expressService->getIvySessionByReference($referenceId);
            if ($ivyPaymentSession === null) {
                throw new IvyException('ivy session not found by refenceId ' . $referenceId);
            }
            $sessionId = $request->getSession()->getId();
            if ($sessionId !== $ivyPaymentSession->getExpressTempData()['sessionId']) {
                throw new IvyException('try to finish express order in other session');
            }

            $swOrderId = $ivyPaymentSession->getSwOrderId();
            $swOrder = $this->expressService->getExpressOrder($swOrderId, $salesChannelContext);

            $this->expressService->updateIvyExpressOrder($ivyPaymentSession, $swOrder->getOrderNumber(), (string)$ivyOrderId, $salesChannelContext);

            $page = $this->genericLoader->load($request, $salesChannelContext);
            $page = CheckoutFinishPage::createFrom($page);
            if ($page->getMetaInformation()) {
                $page->getMetaInformation()->setRobots('noindex,follow');
            }

            Profiler::trace(
                'finish-page-order-loading',
                static function () use ($page, $swOrder): void {
                    $page->setOrder($swOrder);
                }
            );

            $page->setChangedPayment(false);
            $page->setPaymentFailed(false);

            if ($page->getOrder()->getItemRounding()) {
                $salesChannelContext->setItemRounding($page->getOrder()->getItemRounding());
                $salesChannelContext->getContext()->setRounding($page->getOrder()->getItemRounding());
            }
            if ($page->getOrder()->getTotalRounding()) {
                $salesChannelContext->setTotalRounding($page->getOrder()->getTotalRounding());
            }
            $this->hook(new CheckoutFinishPageLoadedHook($page, $salesChannelContext));
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
