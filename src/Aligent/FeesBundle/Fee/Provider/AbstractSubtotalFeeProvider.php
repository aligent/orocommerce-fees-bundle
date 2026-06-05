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
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\CurrencyBundle\Exception\InvalidRoundingTypeException;
use Oro\Bundle\CurrencyBundle\Rounding\RoundingServiceInterface;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Model\Subtotal;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Provider\AbstractSubtotalProvider;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Provider\SubtotalProviderConstructorArguments;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractSubtotalFeeProvider extends AbstractSubtotalProvider implements
    SubtotalFeeProviderInterface
{
    protected ConfigManager $configManager;
    protected TranslatorInterface $translator;
    protected RoundingServiceInterface $rounding;

    public function __construct(
        SubtotalProviderConstructorArguments $arguments,
        ConfigManager $configManager,
        TranslatorInterface $translator,
        RoundingServiceInterface $rounding,
    ) {
        parent::__construct($arguments);
        $this->configManager = $configManager;
        $this->translator = $translator;
        $this->rounding = $rounding;
    }

    /**
     * @throws InvalidRoundingTypeException
     */
    public function getSubtotal($entity): Subtotal
    {
        if (!$this->isSupported($entity)) {
            throw new \InvalidArgumentException('Entity not supported for provider');
        }

        $subtotal = new Subtotal();
        $subtotal
            ->setType($this->getName())
            ->setSortOrder($this->getSortOrder())
            ->setLabel($this->getFeeLabel())
            ->setCurrency($this->getBaseCurrency($entity));

        $fee = $this->getFeeAmount($entity);

        if ($fee === null) {
            return $subtotal->setVisible(false)->setAmount(0.0);
        }

        return $subtotal
            ->setVisible(true)
            ->setAmount($this->rounding->round($fee));
    }

    abstract protected function getFeeAmount(mixed $entity): ?float;
    abstract protected function getFeeLabel(): string;
    abstract protected function getSortOrder(): int;

    public function getType(): string
    {
        return FeeProviderInterface::TYPE_SUBTOTAL;
    }

    protected function getConfiguration(string $key, mixed $default = null): mixed
    {
        return $this->configManager->get(Configuration::getConfigKeyByName($key)) ?? $default;
    }
}
