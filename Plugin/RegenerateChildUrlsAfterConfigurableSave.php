<?php
declare(strict_types=1);

namespace Kinex\ConfigurableVariantUrl\Plugin;

use Kinex\ConfigurableVariantUrl\Model\VariantUrlRewriteResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\SaveHandler;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Throwable;

// Hooks SaveHandler (not catalog_product_save_after on the parent) so
// catalog_product_super_link is already committed when we read it.
class RegenerateChildUrlsAfterConfigurableSave
{
    public function __construct(
        private readonly VariantUrlRewriteResolver $resolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param mixed $arguments
     */
    public function afterExecute(
        SaveHandler $subject,
        ProductInterface $result,
        ProductInterface $entity,
        $arguments = []
    ): ProductInterface {
        if ($entity->getTypeId() !== Configurable::TYPE_CODE) {
            return $result;
        }

        try {
            $this->regenerateChildUrls($entity);
        } catch (Throwable $e) {
            $this->logger->error(
                'Kinex_ConfigurableVariantUrl: failed to regenerate variant alias URLs for configurable #'
                . $entity->getId() . '. The product and its variant links were still saved correctly. '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $result;
    }

    private function regenerateChildUrls(ProductInterface $entity): void
    {
        $extensionAttributes = $entity->getExtensionAttributes();
        $childIds = $extensionAttributes ? $extensionAttributes->getConfigurableProductLinks() : null;

        if ($childIds === null) {
            return;
        }

        foreach ($childIds as $childId) {
            try {
                $childProduct = $this->productRepository->getById((int) $childId);
            } catch (NoSuchEntityException $e) {
                continue;
            }
            $this->resolver->generateForProduct($childProduct);
        }
    }
}
