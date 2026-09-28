// Mixin on Magento_ConfigurableProduct/js/configurable (used when Swatches is
// off or an attribute has no swatch type).
define([
    'jquery'
], function ($) {
    'use strict';

    return function (widget) {
        $.widget('mage.configurable', widget, {
            _create: function () {
                this._super();
                this._variantUrlSyncActive = true;
                this._bindVariantUrlHistory();
            },

            _overrideDefaults: function () {
                this._super();

                try {
                    this._applyPreselectedChild();
                } catch (e) {
                    // never block normal widget init
                }
            },

            _applyPreselectedChild: function () {
                var childId = this.options.spConfig.preselectedChildId,
                    index = this.options.spConfig.index || {},
                    preselected = childId && index[childId];

                if (!preselected) {
                    return;
                }

                this.options.values = this.options.values || {};

                $.each(preselected, $.proxy(function (attributeId, optionId) {
                    if (typeof this.options.values[attributeId] === 'undefined') {
                        this.options.values[attributeId] = optionId;
                    }
                }, this));
            },

            _configureElement: function (element) {
                this._super(element);

                try {
                    this._syncVariantUrl();
                } catch (e) {
                    // never break selection over an address-bar sync failure
                }
            },

            _syncVariantUrl: function () {
                var childId = this.inputSimpleProduct.val(),
                    childUrls = this.options.spConfig.childUrls || {},
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
                var childUrls = this.options.spConfig.childUrls || {},
                    index = this.options.spConfig.index || {},
                    currentHref = window.location.href,
                    matchedChildId = null;

                $.each(childUrls, function (childId, url) {
                    if (url === currentHref) {
                        matchedChildId = childId;
                        return false;
                    }
                });

                if (!matchedChildId || !index[matchedChildId]) {
                    return;
                }

                this._variantUrlSyncActive = false;

                try {
                    this.options.values = $.extend({}, this.options.values, index[matchedChildId]);
                    this._configureForValues();
                } finally {
                    this._variantUrlSyncActive = true;
                }
            }
        });

        return $.mage.configurable;
    };
});
