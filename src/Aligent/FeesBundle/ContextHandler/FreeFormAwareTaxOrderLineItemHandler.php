<?php
/**
 * Extends the core Oro TaxBundle OrderLineItemHandler
 * to add support for FreeForm Line Items
 *
 * As per Oro Support Ticket: OSD-3930
 *
 * @category  Aligent
 * @package
 * @author    Chris Rossi <chris.rossi@aligent.com.au>
 * @copyright 2019 Aligent Consulting.
 * @license
 * @link      http://www.aligent.com.au/
 */
namespace Aligent\FeesBundle\ContextHandler;

use Aligent\FeesBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OrderBundle\Entity\OrderHolderInterface;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\TaxBundle\Model\TaxCodeInterface;
use Oro\Bundle\TaxBundle\OrderTax\ContextHandler\OrderLineItemHandler;

class FreeFormAwareTaxOrderLineItemHandler extends OrderLineItemHandler
{
    protected ConfigManager $configManager;

    public function setConfigManager(ConfigManager $configManager): void
    {
        $this->configManager = $configManager;
    }

    /**
     * NOTE: The parent getProductTaxCode method says it returns an instance of TaxCodeInterface
     *       when in reality it returns a string (ie TaxCodeInterface::getCode()).
     */
    protected function getProductTaxCode(OrderHolderInterface $lineItem): ?string
    {
        $taxCode = parent::getProductTaxCode($lineItem);

        /** @var Product|null $product */
        $product = $lineItem->getProduct();

        if ($taxCode === null && !$product) {
            /**
             * If there is no tax code for this Product, then fall back to the default
             * which was defined by the FeesBundle configuration
             * This allows FreeForm Line Items to have tax rates calculated for them.
             * NOTE: The !$lineItem->getProduct() check ensures that this is ONLY applied
             *       to Freeform LineItems, otherwise it would apply to ALL Products including
             *       those with no tax code (potentially non-taxed products).
             */

            $globalTaxCode = $this->configManager->get(
                Configuration::getConfigKeyByName(Configuration::DEFAULT_PRODUCT_TAX_CODE)
            );

            if (!empty($globalTaxCode) && is_string($globalTaxCode)) {
                $taxCode = $globalTaxCode;

                // Add to tax code cache
                $cacheKey  = $this->getCacheTaxCodeKey(TaxCodeInterface::TYPE_PRODUCT, $lineItem);
                $this->taxCodes[$cacheKey] = $taxCode;
            }
        }

        return $taxCode;
    }
}
