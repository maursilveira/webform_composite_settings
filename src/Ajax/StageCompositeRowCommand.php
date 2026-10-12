<?php

namespace Drupal\webform_composite_settings\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Writes a sub-element's settings back into its row of the composite table.
 *
 * Nothing is saved server side. The row's hidden inputs, options widget and
 * custom properties field are updated in the browser, so the values persist
 * when the composite element itself is saved, exactly as if they had been
 * typed into the table.
 *
 * @see js/composite-dialog.js
 */
class StageCompositeRowCommand implements CommandInterface {

  /**
   * Constructs a StageCompositeRowCommand.
   *
   * @param string $key
   *   The sub-element key identifying the row.
   * @param array $fields
   *   Values for the row's own inputs, keyed by input name.
   * @param array $options
   *   Values for the row's options widget: 'select' names a predefined option
   *   set (empty for custom), 'custom' is the YAML for a custom list.
   * @param string $custom
   *   YAML for the row's custom properties field.
   */
  public function __construct(
    protected string $key,
    protected array $fields,
    protected array $options,
    protected string $custom,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'webformCompositeSettingsStageRow',
      'key' => $this->key,
      'fields' => $this->fields,
      'options' => $this->options,
      'custom' => $this->custom,
    ];
  }

}
