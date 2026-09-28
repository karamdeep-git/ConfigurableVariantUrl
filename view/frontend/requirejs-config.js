var config = {
    config: {
        mixins: {
            'Magento_ConfigurableProduct/js/configurable': {
                'Kinex_ConfigurableVariantUrl/js/variant-url-sync-mixin': true
            },
            'Magento_Swatches/js/swatch-renderer': {
                'Kinex_ConfigurableVariantUrl/js/swatch-renderer-variant-url-sync-mixin': true
            }
        }
    }
};
