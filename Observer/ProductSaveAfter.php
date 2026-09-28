<?php
declare(strict_types=1);

namespace Kinex\ConfigurableVariantUrl\Observer;

use Kinex\ConfigurableVariantUrl\Model\VariantUrlRewriteResolver;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ProductSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly VariantUrlRewriteResolver $resolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();

        if ($product->getTypeId() !== Type::TYPE_SIMPLE) {
            return;
        }

        try {
            $this->resolver->generateForProduct($product);
        } catch (Throwable $e) {
            $this->logger->error(
                'Kinex_ConfigurableVariantUrl: failed to update the variant alias URL for product #'
                . $product->getId() . '. The product itself was still saved correctly. ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
