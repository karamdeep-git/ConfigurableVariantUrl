<?php
declare(strict_types=1);

namespace Kinex\ConfigurableVariantUrl\Plugin;

use Kinex\ConfigurableVariantUrl\Model\VariantUrlRewriteResolver;
use Magento\ConfigurableProduct\Block\Product\View\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

// Adds childUrls + preselectedChildId to the same spConfig JSON the widget already gets.
class AddVariantDataToConfigurableJsonConfig
{
    public function __construct(
        private readonly VariantUrlRewriteResolver $resolver,
        private readonly RequestInterface $request,
        private readonly UrlInterface $urlBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterGetJsonConfig(Configurable $subject, string $result): string
    {
        try {
            return $this->buildConfig($subject, $result);
        } catch (Throwable $e) {
            $this->logger->error(
                'Kinex_ConfigurableVariantUrl: failed to augment configurable JSON config, '
                . 'falling back to the unmodified core config. ' . $e->getMessage(),
                ['exception' => $e]
            );
            return $result;
        }
    }

    private function buildConfig(Configurable $subject, string $result): string
    {
        $allowedProducts = $subject->getAllowProducts();
        if (empty($allowedProducts)) {
            return $result;
        }

        $childIds = [];
        foreach ($allowedProducts as $product) {
            $childIds[] = (int) $product->getId();
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $requestPaths = $this->resolver->getChildRequestPaths($childIds, $storeId);

        $config = $this->json->unserialize($result);

        if (!empty($requestPaths)) {
            $childUrls = [];
            foreach ($requestPaths as $childId => $requestPath) {
                $childUrls[$childId] = $this->urlBuilder->getUrl('', ['_direct' => $requestPath]);
            }
            $config['childUrls'] = $childUrls;
        }

        $requestedChildId = (int) $this->request->getParam(VariantUrlRewriteResolver::CHILD_PARAM);
        if ($requestedChildId && isset($config['index'][$requestedChildId])) {
            $config['preselectedChildId'] = $requestedChildId;
        }

        return $this->json->serialize($config);
    }
}
