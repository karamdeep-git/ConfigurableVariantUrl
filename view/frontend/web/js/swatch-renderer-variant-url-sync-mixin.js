// Mixin on Magento_Swatches/js/swatch-renderer (mage.configurable is never
// instantiated when swatches are used).
define([
    'jquery'
], function ($) {
    'use strict';

    return function (widget) {
        $.widget('mage.SwatchRenderer', widget, {
            _create: function () {
                // don't push history for the initial auto-selection during init
                this._variantUrlSyncActive = false;
                this._super();
                this._bindVariantUrlHistory();

                setTimeout($.proxy(function () {
                    this._variantUrlSyncActive = true;
                }, this), 0);
            },

            _getSelectedAttributes: function () {
                var selectedAttributes = this._super() || {};

                try {
                    return $.extend({}, selectedAttributes, this._kinexPreselectedAttributeCodes());
                } catch (e) {
                    return selectedAttributes;
                }
            },

            _kinexPreselectedAttributeCodes: function () {
                var childId = this.options.jsonConfig.preselectedChildId,
                    index = this.options.jsonConfig.index || {},
                    // _sortAttributes() reindexes jsonConfig.attributes into a plain array;
                    // mappedAttributes keeps the original id-keyed copy
                    attributes = this.options.jsonConfig.mappedAttributes || this.options.jsonConfig.attributes || {},
                    preselected = childId && index[childId],
                    result = {};

                if (!preselected) {
                    return result;
                }

                $.each(preselected, function (attributeId, optionId) {
                    var attribute = attributes[attributeId];

                    if (attribute && attribute.code) {
                        result[attribute.code] = optionId;
                    }
                });

                return result;
            },

            _OnClick: function ($this, $widget) {
                this._super($this, $widget);

                try {
                    this._syncVariantUrl();
                } catch (e) {
                    // never break swatch selection over an address-bar sync failure
                }
            },

            _OnChange: function ($this, $widget) {
                this._super($this, $widget);

                try {
                    this._syncVariantUrl();
                } catch (e) {
                    // never break swatch selection over an address-bar sync failure
                }
            },

            _syncVariantUrl: function () {
                var childId = this.getProductId(),
                    childUrls = this.options.jsonConfig.childUrls || {},
                    targetUrl;

                if (!this._variantUrlSyncActive || !childId || !childUrls[childId]) {
                    return;
                }

                targetUrl = childUrls[childId];

                if (targetUrl !== window.location.href) {
                    window.history.pushState({kinexVariantChildId: childId}, '', targetUrl);
                }
            },

            _bindVariantUrlHistory: function () {
                var self = this;

                $(window).on('popstate.kinexVariantUrlSync', function () {
                    try {
                        self._resyncFromCurrentUrl();
                    } catch (e) {
                        self._variantUrlSyncActive = true;
                    }
                });
            },

            _resyncFromCurrentUrl: function () {
                var childUrls = this.options.jsonConfig.childUrls || {},
                    index = this.options.jsonConfig.index || {},
                    attributes = this.options.jsonConfig.mappedAttributes || this.options.jsonConfig.attributes || {},
                    currentHref = window.location.href,
                    matchedChildId = null,
                    attributeCodeMap = {};

                $.each(childUrls, function (childId, url) {
                    if (url === currentHref) {
                        matchedChildId = childId;
                        return false;
                    }
                });

                if (!matchedChildId || !index[matchedChildId]) {
                    return;
                }

                $.each(index[matchedChildId], function (attributeId, optionId) {
                    var attribute = attributes[attributeId];

                    if (attribute && attribute.code) {
                        attributeCodeMap[attribute.code] = optionId;
                    }
                });

                this._variantUrlSyncActive = false;

                try {
                    this._EmulateSelected(attributeCodeMap);
                } finally {
                    this._variantUrlSyncActive = true;
                }
            }
        });

        return $.mage.SwatchRenderer;
    };
});
