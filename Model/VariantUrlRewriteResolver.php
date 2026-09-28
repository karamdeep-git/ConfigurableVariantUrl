<?php
declare(strict_types=1);

namespace Kinex\ConfigurableVariantUrl\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogUrlRewrite\Model\GetVisibleForStores;
use Magento\CatalogUrlRewrite\Model\Product\CanonicalUrlRewriteGenerator;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Psr\Log\LoggerInterface;

/**
 * Manages alias url_rewrite rows for NVI simple products under a custom entity_type,
 * so core's visibility-driven rewrite generate/delete logic never touches them.
 */
class VariantUrlRewriteResolver
{
    public const ENTITY_TYPE = 'kinex_configurable_variant';

    // target_path segment name carrying the originally requested child id
    public const CHILD_PARAM = 'sp_id';

    public function __construct(
        private readonly ConfigurableResource $configurableResource,
        private readonly CanonicalUrlRewriteGenerator $canonicalUrlRewriteGenerator,
        private readonly GetVisibleForStores $getVisibleForStores,
        private readonly UrlFinderInterface $urlFinder,
        private readonly UrlPersistInterface $urlPersist,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function generateForProduct(Product $product): void
    {
        $childId = (int) $product->getId();

        if ((int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
            $this->cleanupForProduct($childId);
            return;
        }

        $parentIds = $this->configurableResource->getParentIdsByChild($childId);
        if (empty($parentIds)) {
            $this->cleanupForProduct($childId);
            return;
        }

        if (count($parentIds) > 1) {
            $this->logger->warning(sprintf(
                'Kinex_ConfigurableVariantUrl: product #%d is linked to multiple configurable parents (%s); '
                . 'using the first one for its alternate URL.',
                $childId,
                implode(',', $parentIds)
            ));
        }
        $parentId = (int) reset($parentIds);

        // stores with a per-store visibility override already have a core 'product' rewrite row
        $visibleStoreIds = $this->getVisibleForStores->execute($product);
        $storeIds = array_diff(array_map('intval', $product->getStoreIds()), $visibleStoreIds);

        if (empty($storeIds)) {
            $this->cleanupForProduct($childId);
            return;
        }

        $rows = [];
        foreach ($storeIds as $storeId) {
            try {
                // url_key is per-store; reload scoped to get the right one
                $storeScopedProduct = $this->productRepository->getById($childId, false, $storeId);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            [$row] = $this->canonicalUrlRewriteGenerator->generate($storeId, $storeScopedProduct);
            $row->setEntityType(self::ENTITY_TYPE);
            $row->setTargetPath(sprintf(
                'catalog/product/view/id/%d/%s/%d',
                $parentId,
                self::CHILD_PARAM,
                $childId
            ));
            $rows[] = $row;
        }

        if (empty($rows)) {
            return;
        }

        try {
            $this->urlPersist->replace($rows);
        } catch (UrlAlreadyExistsException $e) {
            $this->logger->warning(sprintf(
                'Kinex_ConfigurableVariantUrl: could not generate an alternate URL for product #%d — '
                . 'its URL key collides with an existing rewrite for the same store. %s',
                $childId,
                $e->getMessage()
            ));
        }
    }

    public function cleanupForProduct(int $childId): void
    {
        $this->urlPersist->deleteByData([
            UrlRewrite::ENTITY_ID => $childId,
            UrlRewrite::ENTITY_TYPE => self::ENTITY_TYPE,
        ]);
    }

    /**
     * @param int[] $childIds
     * @return array<int, string>
     */
    public function getChildRequestPaths(array $childIds, int $storeId): array
    {
        if (empty($childIds)) {
            return [];
        }

        $rewrites = $this->urlFinder->findAllByData([
            UrlRewrite::ENTITY_ID => array_map('intval', $childIds),
            UrlRewrite::ENTITY_TYPE => self::ENTITY_TYPE,
            UrlRewrite::STORE_ID => $storeId,
        ]);

        $map = [];
        foreach ($rewrites as $rewrite) {
            $map[(int) $rewrite->getEntityId()] = $rewrite->getRequestPath();
        }

        return $map;
    }
}
