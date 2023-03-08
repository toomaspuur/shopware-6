<?php

declare(strict_types=1);
/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Services;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerRegistry;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\TokenExpiredException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use WizmoGmbh\IvyPayment\PaymentHandler\IvyPaymentHandler;

class IvyPaymentService
{
    private EntityRepositoryInterface $paymentMethodRepository;

    private PaymentHandlerRegistry $paymentHandlerRegistry;

    private EntityRepositoryInterface $orderTransactionRepository;

    private OrderTransactionStateHandler $transactionStateHandler;

    private LoggerInterface $logger;

    public function __construct(
        EntityRepositoryInterface $paymentMethodRepository,
        PaymentHandlerRegistry $paymentHandlerRegistry,
        EntityRepositoryInterface $orderTransactionRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        LoggerInterface $logger
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentHandlerRegistry = $paymentHandlerRegistry;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->logger = $logger;
    }

    /**
     * @throws AsyncPaymentFinalizeException
     * @throws InvalidTransactionException
     * @throws TokenExpiredException
     * @throws UnknownPaymentMethodException
     *
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress DocblockTypeContradiction
     */
    public function updateTransaction(string $transactionId, string $paymentMethodId, Request $request, SalesChannelContext $context): void
    {
        if ($transactionId === null || !Uuid::isValid($transactionId)) {
            throw new AsyncPaymentProcessException((string) $transactionId, "No valid orderTransactionId was provided.");
        }

        $transaction = $this->getPaymentTransactionStruct($transactionId, $context->getContext());

        /** @var IvyPaymentHandler $paymentHandler */
        $paymentHandler = $this->getPaymentHandlerById($paymentMethodId ?? '', $context->getContext());

        if ($paymentHandler === null) {
            throw new UnknownPaymentMethodException($paymentMethodId);
        }

        try {
            $paymentHandler->finalize($transaction, $request, $context);
        } catch (PaymentProcessException $e) {
            $this->logger->error('A PaymentProcessException occurred during finalizing async payment', ['orderTransactionId' => $transactionId, 'exceptionMessage' => $e->getMessage()]);
            $this->transactionStateHandler->fail($transactionId, $context->getContext());
        }
    }

    public function cancelPayment(string $transactionId, SalesChannelContext $context): void {
        $this->transactionStateHandler->cancel($transactionId, $context->getContext());
    }

    /**
     * @throws UnknownPaymentMethodException
     */
    private function getPaymentHandlerById(string $paymentMethodId, Context $context): ?AsynchronousPaymentHandlerInterface
    {
        $criteria = new Criteria([$paymentMethodId]);
        $criteria->addAssociation('appPaymentMethod.app');
        $paymentMethods = $this->paymentMethodRepository->search($criteria, $context);

        /** @var PaymentMethodEntity|null $paymentMethod */
        $paymentMethod = $paymentMethods->get($paymentMethodId);
        if ($paymentMethod === null) {
            throw new UnknownPaymentMethodException($paymentMethodId);
        }

        return $this->paymentHandlerRegistry->getAsyncHandlerForPaymentMethod($paymentMethod);
    }

    /**
     * @throws InvalidTransactionException
     * @psalm-suppress PossiblyNullArgument
     */
    private function getPaymentTransactionStruct(string $orderTransactionId, Context $context): AsyncPaymentTransactionStruct
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('paymentMethod.appPaymentMethod.app');
        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($orderTransaction === null) {
            throw new InvalidTransactionException($orderTransactionId);
        }

        return new AsyncPaymentTransactionStruct($orderTransaction, $orderTransaction->getOrder(), '');
    }
}
