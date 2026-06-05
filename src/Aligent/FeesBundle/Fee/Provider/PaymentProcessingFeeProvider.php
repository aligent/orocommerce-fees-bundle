<?php
/**
 * @category  Aligent
 * @package
 * @author    Chris Rossi <chris.rossi@aligent.com.au>
 * @copyright 2022 Aligent Consulting.
 * @license
 * @link      http://www.aligent.com.au/
 */
namespace Aligent\FeesBundle\Fee\Provider;

use Aligent\FeesBundle\DependencyInjection\Configuration;
use Brick\Math\BigDecimal;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CheckoutBundle\Entity\Checkout;
use Oro\Bundle\CheckoutBundle\Payment\Method\EntityPaymentMethodsProvider;
use Oro\Bundle\CurrencyBundle\Exception\InvalidRoundingTypeException;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Model\Subtotal;
use Oro\Bundle\PricingBundle\SubtotalProcessor\SubtotalProviderRegistry;
use Oro\Bundle\PricingBundle\SubtotalProcessor\TotalProcessorProvider;

class PaymentProcessingFeeProvider extends AbstractSubtotalFeeProvider
{
    const NAME = 'payment_processing_fee';
    const SUBTOTAL_SORT_ORDER = 200;

    protected EntityPaymentMethodsProvider $entityPaymentMethodsProvider;
    protected SubtotalProviderRegistry $subtotalProviderRegistry;
    protected TotalProcessorProvider $totalProcessorProvider;
    protected ManagerRegistry $doctrine;

    protected bool $forceRecalculation = false;

    public function isSupported(mixed $entity): bool
    {
        return $entity instanceof Order || $entity instanceof Checkout;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getSortOrder(): int
    {
        return self::SUBTOTAL_SORT_ORDER;
    }

    protected function getFeeLabel(): string
    {
        return $this->translator->trans('aligent.fees.checkout.subtotal.processing_fee.label');
    }

    protected function getFeeAmount(mixed $entity): ?float
    {
        if (!$this->isSupported($entity)) {
            throw new \InvalidArgumentException('Entity not supported for provider');
        }

        if ($entity instanceof Order && $entity->getId() && !$this->forceRecalculation) {
            // Load fee from persisted Order via Doctrine metadata
            $persisted = $this->getOrderFieldValue($entity, 'processing_fee');
            if ($persisted !== null) {
                return (float) $persisted;
            }
        }

        // Calculate the fee
        $fee = $this->calculateProcessingFee($entity);
        if ($entity instanceof Order) {
            $this->setOrderFieldValue($entity, 'processing_fee', $fee);
        }

        return $fee;
    }

    /**
     * @throws InvalidRoundingTypeException
     */
    protected function calculateProcessingFee(object $entity): ?float
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $feeConfiguration = $this->getPaymentMethodFeeConfiguration($entity);
        if (!$feeConfiguration) {
            return null;
        }

        $percentage = $feeConfiguration['percentage'] / 100;

        $subtotals = $this->getSubtotalsForEntity($entity);
        $subtotal = $this->totalProcessorProvider->getTotalForSubtotals($entity, $subtotals);

        $amount = BigDecimal::of($subtotal->getAmount());

        $feeAmount = $amount->multipliedBy($percentage)->toFloat();

        return $this->rounding->round($feeAmount);
    }

    /**
     * @param object $entity
     * @return ArrayCollection<int,Subtotal>
     */
    protected function getSubtotalsForEntity(object $entity): ArrayCollection
    {
        $subtotals = [];
        foreach ($this->subtotalProviderRegistry->getSupportedProviders($entity) as $provider) {
            if ($provider instanceof self) {
                // Skip self when determining subtotal of Entity
                continue;
            }

            $entitySubtotals = $provider->getSubtotal($entity);
            $entitySubtotals = is_object($entitySubtotals) ? [$entitySubtotals] : (array) $entitySubtotals;
            foreach ($entitySubtotals as $subtotal) {
                $subtotals[] = $subtotal;
            }
        }

        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions
        usort($subtotals, function (Subtotal $leftSubtotal, Subtotal $rightSubtotal) {
            return $leftSubtotal->getSortOrder() - $rightSubtotal->getSortOrder();
        });

        return new ArrayCollection($subtotals);
    }

    protected function isEnabled(): bool
    {
        return (bool)$this->getConfiguration(Configuration::PROCESSING_FEE_ENABLED, false);
    }

    /**
     * @param object $entity
     * @return array<string,mixed>|null
     */
    protected function getPaymentMethodFeeConfiguration(object $entity): ?array
    {
        $paymentMethod = $this->getPaymentMethod($entity);
        if (!$paymentMethod) {
            return null;
        }

        $feeConfiguration = (array)$this->getConfiguration(Configuration::PROCESSING_FEE_PAYMENT_METHODS);

        if (!array_key_exists($paymentMethod, $feeConfiguration)) {
            return null;
        }

        $paymentMethodFeeConfiguration = $feeConfiguration[$paymentMethod];

        if (!array_key_exists('percentage', $paymentMethodFeeConfiguration)) {
            return null;
        }

        if ($paymentMethodFeeConfiguration['percentage'] > 0) {
            return $paymentMethodFeeConfiguration;
        }

        return null;
    }

    protected function getPaymentMethod(object $entity): ?string
    {
        if ($entity instanceof Checkout) {
            return $entity->getPaymentMethod();
        }
        if ($entity instanceof Order) {
            $paymentMethods = $this->entityPaymentMethodsProvider->getPaymentMethods($entity);
            return current($paymentMethods) ?: null;
        }
        return null;
    }

    private function getOrderFieldValue(Order $order, string $field): mixed
    {
        $em = $this->doctrine->getManagerForClass(Order::class);
        if (!$em) {
            return null;
        }
        return $em->getClassMetadata(Order::class)->getFieldValue($order, $field);
    }

    private function setOrderFieldValue(Order $order, string $field, mixed $value): void
    {
        $em = $this->doctrine->getManagerForClass(Order::class);
        if (!$em) {
            return;
        }
        $em->getClassMetadata(Order::class)->setFieldValue($order, $field, $value);
    }

    public function setDoctrine(ManagerRegistry $doctrine): void
    {
        $this->doctrine = $doctrine;
    }

    public function setForceRecalculation(bool $forceRecalculation): void
    {
        $this->forceRecalculation = $forceRecalculation;
    }

    public function setEntityPaymentMethodsProvider(EntityPaymentMethodsProvider $entityPaymentMethodsProvider): void
    {
        $this->entityPaymentMethodsProvider = $entityPaymentMethodsProvider;
    }

    public function setSubtotalProviderRegistry(SubtotalProviderRegistry $subtotalProviderRegistry): void
    {
        $this->subtotalProviderRegistry = $subtotalProviderRegistry;
    }

    public function setTotalProcessorProvider(TotalProcessorProvider $totalProcessorProvider): void
    {
        $this->totalProcessorProvider = $totalProcessorProvider;
    }
}
