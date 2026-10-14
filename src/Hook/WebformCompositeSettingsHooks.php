<?php

namespace Drupal\webform_composite_settings\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform_composite_settings\CompositeSubElementUi;

/**
 * Hook implementations for webform_composite_settings.
 *
 * On Drupal 11.1 and later the #[Hook] attributes below are what registers
 * these implementations. On Drupal 10 the attributes are inert and the
 * procedural functions in the .module file call in here instead; they carry
 * #[LegacyHook] so that newer cores do not run both.
 *
 * @see webform_composite_settings.module
 */
class WebformCompositeSettingsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.webform_composite_settings') {
      return NULL;
    }
    $output = '<h2>' . $this->t('About') . '</h2>';
    $output .= '<p>' . $this->t('The Webform Composite Settings module exposes the full element settings form for the sub-elements of a Webform "Custom composite" element. Each row of the Elements table on a custom composite gets a <em>Sub-element settings</em> button that opens the settings form of that sub-element in a dialog, so settings that the table itself does not collect no longer have to be written as YAML.') . '</p>';
    $output .= '<p>' . $this->t('The dialog does not save the webform. Its result is written back into the row and stored when the composite element itself is saved.') . '</p>';
    $output .= '<p>' . $this->t('For more information, see the <a href=":url">online documentation for the Webform Composite Settings module</a>.', [':url' => 'https://www.drupal.org/project/webform_composite_settings']) . '</p>';
    return $output;
  }

  /**
   * Implements hook_element_info_alter().
   *
   * Appends a process callback to the custom composite's Elements table. Being
   * last in the list, it runs after
   * WebformElementComposite::processWebformElementComposite() has built the
   * table, so it can rework the finished columns.
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(array &$info): void {
    if (isset($info['webform_element_composite'])) {
      $info['webform_element_composite']['#process'][] = [
        CompositeSubElementUi::class,
        'processCompositeTable',
      ];
    }
  }

}
