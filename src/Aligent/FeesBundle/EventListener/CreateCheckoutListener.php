<?php
/**
 * Listener fires when Checkout is Created/Started
 * For all Registered Fees, it determines whether each one applies to the current Checkout.
 * If so, the Fee is injected into the Checkout as a new LineItem.
 *
 * @category  Aligent
 * @package
 * @author    Chris Rossi <chris.rossi@aligent.com.au>
 * @copyright 2019 Aligent Consulting.
 * @license
 * @link      http://www.aligent.com.au/
 */
namespace Aligent\FeesBundle\EventListener;

use Aligent\FeesBundle\Fee\Provider\FeeProviderInterface;
use Aligent\FeesBundle\Fee\Provider\FeeProviderRegistry;
use Aligent\FeesBundle\Fee\Provider\LineItemFeeProviderInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ActionBundle\Model\ActionData;
use Oro\Bundle\CheckoutBundle\Entity\Checkout;
use Oro\Component\Action\Event\ExtendableConditionEvent;

class CreateCheckoutListener
{
    protected FeeProviderRegistry $feeRegistry;
    protected ManagerRegistry $managerRegistry;

    public function __construct(FeeProviderRegistry $feeRegistry, ManagerRegistry $registry)
    {
        $this->feeRegistry = $feeRegistry;
        $this->managerRegistry = $registry;
    }

    public function onStartCheckoutConditionCheck(ExtendableConditionEvent $event): void
    {
        $checkout = $event->getData()?->offsetGet('checkout');
        if (!$checkout instanceof Checkout) {
            return;
        }

        $needsFlush = false;

        /**
         * Only get Fees which inject LineItems
         */
        foreach ($this->feeRegistry->getProviders(FeeProviderInterface::TYPE_LINE_ITEM) as $provider) {
            if (!$provider instanceof LineItemFeeProviderInterface) {
                continue;
            }

            foreach ($provider->getCheckoutLineItems($checkout) as $lineItem) {
                // Fee has generated one or more LineItems, inject them into Checkout
                $checkout->addLineItem($lineItem);
                $needsFlush = true;
            }
        }

        /**
         * NOTE: When creating a Checkout from a ShoppingList, the line items are persisted
         * by the workflow (after this event has fired and injected the LineItems).
         *
         * However, when creating a Checkout from a previous Order ("Re-Order"),
         * the workflow persists BEFORE this event fires (so the injected LineItems are lost).
         *
         * If any LineItems (fees) have been injected, manually persist the Checkout entity.
         */
        if ($needsFlush) {
            $manager = $this->managerRegistry->getManagerForClass(Checkout::class);
            $manager->persist($checkout);
            $manager->flush();
        }
    }
}
