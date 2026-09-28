<?php
declare(strict_types=1);

namespace Kinex\ConfigurableVariantUrl\Observer;

use Kinex\ConfigurableVariantUrl\Model\VariantUrlRewriteResolver;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

// Doesn't cascade to a deleted configurable parent's children — their stale alias
// rows just 404 via core's noroute, same as any dangling product URL.
class ProductDeleteAfter implements ObserverInterface
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

        try {
            $this->resolver->cleanupForProduct((int) $product->getId());
        } catch (Throwable $e) {
            $this->logger->error(
                'Kinex_ConfigurableVariantUrl: failed to clean up variant alias URLs for deleted product #'
                . $product->getId() . '. The product was still deleted correctly. ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
