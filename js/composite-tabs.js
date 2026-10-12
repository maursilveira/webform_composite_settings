/**
 * @file
 * Initialises the settings dialog's tabs.
 *
 * Webform's own behaviour is deliberately kept away from this markup: it calls
 * Tabby with the document wide selector 'div.webform-tabs
 * .webform-tabs-item-list', which resolves to the element form behind the
 * dialog rather than to the dialog's own tabs.
 *
 * @see \Drupal\webform_composite_settings\CompositeSubElementUi::scopeTabs()
 */

(function (Drupal, once) {
  Drupal.behaviors.webformCompositeSettingsTabs = {
    attach(context) {
      // Tabby is a CDN hosted library and may legitimately be unavailable, in
      // which case every pane stays visible and the form is still usable.
      if (!window.Tabby) {
        return;
      }

      once(
        'webform-composite-settings-tabs',
        '.webform-composite-settings-tabs',
        context,
      ).forEach(function (wrapper) {
        const list = wrapper.querySelector('.webform-tabs-item-list');
        if (!list || !list.id) {
          return;
        }

        // Tabby resolves its first argument with document.querySelector, so
        // this has to match nothing but the current dialog's tab list.
        new window.Tabby('#' + list.id, {
          default: '[data-tab-index="0"]',
        });
      });
    },
  };
})(Drupal, once);
