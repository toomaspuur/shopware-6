<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\Checkout\Payment\Controller;

use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Checkout\Payment\Exception\TokenExpiredException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\ShopwareHttpException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use WizmoGmbh\IvyPayment\Logger\IvyLogger;
use WizmoGmbh\IvyPayment\Services\IvyPaymentService;
use WizmoGmbh\IvyPayment\Express\Service\ExpressService;


class IvyPaymentController extends StorefrontController
{
    private IvyPaymentService $paymentService;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private IvyLogger $logger;

    private ExpressService $expressService;

    /**
     * @param IvyPaymentService $paymentService
     * @param TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2
     * @param IvyLogger $logger
     * @param ExpressService $expressService
     */
    public function __construct(
        IvyPaymentService $paymentService,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        IvyLogger $logger,
        ExpressService $expressService
    ) {
        $this->paymentService = $paymentService;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->logger = $logger;
        $this->expressService = $expressService;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/failed-transaction", name="frontend.ivypayment.failed.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     * @RouteScope(scopes={"storefront"})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     */
    public function failedTransaction(Request $request): Response
    {
        $finishUrl = '/account/order';
        $referenceId = $request->get('reference');

        $order = $this->expressService->getIvyOrderByReference($referenceId);

        if ($order !== null) {
            $finishUrl = '/account/order/edit/' . $order->getId() . '?error-code=CHECKOUT__UNKNOWN_ERROR';
        }

        return new RedirectResponse($finishUrl);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/update-transaction", name="ivypayment.update.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true,"csrf_protected"=false,"auth_required"=false})
     * @RouteScope(scopes={"storefront"})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException|\Doctrine\DBAL\Exception
     * @psalm-suppress InvalidArrayAccess
     */
    public function updateTransaction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->info('received webhook');

        $type = $request->request->get('type');
        /** @var array $payload */
        $payload = $request->request->get('payload');

        if (empty($type) || empty($payload)) {
            $this->logger->error('bad webhook request');
            return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
        }

        $isValid = $this->expressService->isValidRequest($request, $salesChannelContext);

        if (!$isValid) {
            $this->logger->error('webhook request: unauthenticated request');
            return new JsonResponse(null, Response::HTTP_FORBIDDEN);
        }

        $this->logger->info('webhook request: valid request ==> '. $type);

        if ($type === 'order_created' || $type === 'order_updated') {
            if (!isset($payload['status'])) {
                $this->logger->error('bad webhook request');
                return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
            }
    
            $this->logger->debug('webhook payload: ' . \print_r($payload, true));

            $referenceId = $payload['referenceId'];
            $paymentToken = $payload['metadata']['_sw_payment_token'] ?? null;

            if ($paymentToken === null) {
                $this->logger->error('payment token missing for referenceId: '.$referenceId);
                return new JsonResponse(null, Response::HTTP_NOT_FOUND);
            } else {
                $this->logger->debug('payment token: '.$paymentToken);
                try{
                    $token = $this->tokenFactoryInterfaceV2->parseToken($paymentToken);
                    $transactionId = $token->getTransactionId();
                } catch (ShopwareHttpException $exception) {
                    $this->logger->error('payment token invalid, ignore webhook');
                    return new JsonResponse(null, Response::HTTP_OK);
                }
            }

            $request->request->set('status', $payload['status']);
            $this->logger->debug('set status to: ' . $payload['status'] . ' for referenceId: '.$referenceId);

            $order = $this->expressService->getIvyOrderByReference($referenceId);
            if($order === null) {
                $this->logger->debug('Order does not exist with this referenceId, ignore webhook');
                return new JsonResponse(null, Response::HTTP_OK);
            }

            $this->paymentService->updateTransaction(
                $transactionId,
                $this->expressService->getPaymentMethodId(),
                $request,
                $salesChannelContext
            );
        }

        $this->logger->debug('webhook finished  <== '. $type);
        return new JsonResponse(null, Response::HTTP_OK);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/finalize-transaction", name="frontend.ivypayment.finalize.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     * @RouteScope(scopes={"storefront"})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     */
    public function finalizeTransaction(Request $request, string $status = 'final'): Response
    {
        return $this->redirectToRoute('frontend.ivyexpress.finish', $request->query->all());
    }
}
