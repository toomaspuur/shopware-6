<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Core\Checkout\Payment\Controller;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Checkout\Payment\Exception\TokenExpiredException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\ShopwareHttpException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
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
use WizmoGmbh\IvyPayment\Components\Config\ConfigHandler;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class IvyPaymentController extends StorefrontController
{
    private IvyPaymentService $paymentService;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private IvyLogger $logger;

    private ExpressService $expressService;

    private ConfigHandler $configHandler;
    /**
     * @param IvyPaymentService $paymentService
     * @param TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2
     * @param IvyLogger $logger
     * @param ExpressService $expressService
     * @param ConfigHandler $configHandler
     */
    public function __construct(
        IvyPaymentService $paymentService,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        IvyLogger $logger,
        ExpressService $expressService,
        ConfigHandler $configHandler
    ) {
        $this->paymentService = $paymentService;
        $this->configHandler = $configHandler;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->logger = $logger;
        $this->logger->setName('WEBHOOK');
        $this->expressService = $expressService;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/failed-transaction", name="frontend.ivypayment.failed.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     */
    public function failedTransaction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $finishUrl = '/account/order';

        if ($paymentToken = $request->query->get('_sw_payment_token')) {
            $this->logger->debug('payment token: ' . $paymentToken);
            try {
                $token = $this->tokenFactoryInterfaceV2->parseToken($paymentToken);
                $transactionId = $token->getTransactionId();
                $this->paymentService->cancelPayment($transactionId, $salesChannelContext);
                $referenceId = $request->get('reference');

                $order = $this->expressService->getIvyOrderByReference($referenceId);

                if ($order !== null) {
                    $finishUrl = '/account/order/edit/' . $order->getId() . '?error-code=CHECKOUT__UNKNOWN_ERROR';
                }
            } catch (ShopwareHttpException $exception) {
                $this->logger->error('payment token invalid, ignore cancel');
            }
        }

        return new RedirectResponse($finishUrl);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/update-transaction", name="ivypayment.update.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true,"csrf_protected"=false,"auth_required"=false})
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException|\Doctrine\DBAL\Exception|\WizmoGmbh\IvyPayment\Exception\IvyException
     * @psalm-suppress InvalidArrayAccess
     */
    public function updateTransaction(Request $request, RequestDataBag $inputData, SalesChannelContext $salesChannelContext): Response
    {
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

        $this->logger->info('webhook request: valid request ==> ' . $type);

        if ($type !== 'order_created' && $type !== 'order_updated') {
            $this->logger->debug('skip notification type ' . $type);
            return new JsonResponse(['success' => false, 'error' => 'skip notification type ' . $type], Response::HTTP_OK);
        }

        if (!isset($payload['status'])) {
            $this->logger->error('bad webhook request');
            return new JsonResponse(['success' => false, 'error' => 'bad webhook request'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->debug('webhook payload: ' . \print_r($payload, true));

        $status = $payload['status'];
        $this->logger->info('WebHook status is ' . $status);

        $statusForCreateOrder = \in_array($status, ['paid', 'waiting_for_payment'], true);
        $this->logger->info('status for createOrder ' . \var_export($statusForCreateOrder, true));

        $referenceId = $payload['referenceId'];
        $ivyOrderId = $payload['id'];
        $lockName = 'ivylock_' . $ivyOrderId . '.lock';
        $tmpdir = \sys_get_temp_dir();
        $fp = \fopen($tmpdir . $lockName, 'wb');

        $count = 0;
        $timeoutSecs = 10; //number of seconds of timeout
        $gotLock = true;
        while (!\flock($fp, LOCK_EX | LOCK_NB, $wouldblock)) {
            if ($wouldblock && $count++ < $timeoutSecs) {
                $this->logger->warning($lockName . ' locked by other process. wait for lock release.');
                \sleep(1);
            } else {
                $gotLock = false;
                break;
            }
        }

        if ($gotLock === false) {
            $this->logger->error('timeout: ' . $lockName . ' locked by other process');
            return new JsonResponse(null, Response::HTTP_LOCKED);
        }

        $toCreateOrder = $toUpdateOrder = false;

        $request->request->set('status', $payload['status']);

        $order = $this->expressService->getIvyOrderByReference($referenceId);
        if ($order === null) {
            if (!$statusForCreateOrder) {
                $this->logger->debug('Order does not exist with this referenceId, ignore webhook');
                return new JsonResponse(null, Response::HTTP_OK);
            }
            $toCreateOrder = true;
        } elseif (!$order->getOrderNumber()) {
            if ($statusForCreateOrder) {
                $toCreateOrder = true;
            }
        }

        if ($toCreateOrder) {
            /** @var OrderEntity $order */
            [$order, $token] = $this->expressService->checkoutConfirm($inputData, $payload, $salesChannelContext);

            $config = $this->configHandler->getFullConfig($salesChannelContext);
            $this->logger->info('update order over ivy api');
            $ivyResponse = $this->ivyApiClient->sendApiRequest('order/update', $config, \json_encode([
                'id' => $ivyOrderId,
                'displayId' => $order->getOrderNumber(),
                'referenceId' => $order->getId(),
                'metadata' => [
                    '_sw_payment_token' => $token,
                    'shopwareOrderId' => $order->getOrderNumber()
                ]
            ]));
            $this->logger->info('ivy response: ' . \print_r($ivyResponse, true));
            $outputData = [
                "success" => true
            ];
            $this->logger->info(\print_r($outputData, true));
        }

        $this->logger->info('update order ' . $order->getOrderNumber());
        $this->logger->debug('set status to: ' . $payload['status'] . ' for referenceId: ' . $referenceId);
        $paymentMethodId = $this->expressService->getPaymentMethodId();
        /** @var OrderTransactionEntity $transaction */
        $transaction = $order->getTransactions()->filterByPaymentMethodId($paymentMethodId)->first();

        if ($transaction !== null) {
            $this->paymentService->updateTransaction(
                $transaction->getId(),
                $paymentMethodId,
                $request,
                $salesChannelContext
            );
        } else {
            $this->logger->debug('no ivy-transaction found for referenceId: ' . $referenceId);
        }

        $this->logger->debug('webhook finished  <== ' . $type);
        
        return new JsonResponse(null, Response::HTTP_OK);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/ivypayment/finalize-transaction", name="frontend.ivypayment.finalize.transaction", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
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