<?php

namespace Drupal\webform_composite_settings\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\BaseFormIdInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Url;
use Drupal\webform_composite_settings\Ajax\StageCompositeRowCommand;
use Drupal\webform_composite_settings\CompositeSubElementUi;
use Drupal\webform\Plugin\WebformElement\WebformCompositeBase;
use Drupal\webform\Plugin\WebformElementInterface;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\Utility\WebformArrayHelper;
use Drupal\webform\Utility\WebformElementHelper;
use Drupal\webform\Utility\WebformYaml;
use Drupal\webform\WebformInterface;
use Drupal\webform_ui\Form\WebformUiElementFormInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Configures one sub-element of a Webform custom composite element.
 *
 * Rather than rebuilding each element type's settings form, this renders the
 * element plugin's own configuration form unchanged. Because the sub-element
 * gets a form to itself, its fields keep their native 'properties[...]' names,
 * so the plugin's '#states' selectors and validation both resolve as they do on
 * a normal element form.
 *
 * The form does not save the webform. It is opened from the composite element's
 * own form, seeded with that row's current values (saved or not), and on submit
 * writes its result back into the row, where it is persisted along with the
 * composite when that form is saved. Without JavaScript there is no row to
 * write to, so a plain submit saves the webform directly instead.
 *
 * Element plugins reach back to the form object for context, so this implements
 * the same interface webform_ui's element forms do.
 *
 * @see \Drupal\webform\Plugin\WebformElementBase::form()
 * @see \Drupal\webform_composite_settings\Ajax\StageCompositeRowCommand
 */
class CompositeSubElementForm extends FormBase implements BaseFormIdInterface, WebformUiElementFormInterface {

  /**
   * Sub-element properties held by the table's own visible inputs.
   *
   * The type select and the options widget. They are read from and written to
   * directly, and never travel in the custom properties YAML.
   */
  protected const TABLE_OWNED = ['type', 'options'];

  /**
   * The webform element plugin manager.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface
   */
  protected WebformElementManagerInterface $elementManager;

  /**
   * The webform options storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $optionsStorage;

  /**
   * The webform holding the composite element.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected WebformInterface $webform;

  /**
   * The composite element's key within the webform.
   *
   * @var string
   */
  protected string $compositeElementKey;

  /**
   * The sub-element's key within the composite.
   *
   * @var string
   */
  protected string $subElementKey;

  /**
   * The sub-element render array being configured.
   *
   * @var array
   */
  protected array $subElement = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->elementManager = $container->get('plugin.manager.webform.element');
    $instance->optionsStorage = $container->get('entity_type.manager')->getStorage('webform_options');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webform_composite_settings_sub_element_form';
  }

  /**
   * {@inheritdoc}
   *
   * Declaring the regular element form as the base form makes every
   * hook_form_webform_ui_element_form_alter() implementation run here too.
   * Modules that add settings to element forms rely on those alters to finish
   * the job: webform_validation, for one, adds checkbox groups whose unchecked
   * "key: 0" entries it strips with a validator attached that way. Without it
   * the modal would store what the regular form never does.
   */
  public function getBaseFormId(): string {
    return 'webform_ui_element_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?WebformInterface $webform = NULL, $key = NULL, $composite_key = NULL): array {
    $parent_element = $webform ? $webform->getElementDecoded($key) : NULL;
    if (!$parent_element
      || ($parent_element['#type'] ?? NULL) !== 'webform_custom_composite'
      || !isset($parent_element['#element'][$composite_key])) {
      throw new NotFoundHttpException();
    }

    $this->webform = $webform;
    $this->compositeElementKey = $key;
    $this->subElementKey = $composite_key;

    // The row's unsaved values arrive with the request that opens the dialog,
    // which is what lets a second opening before the composite is saved start
    // from those values rather than from configuration. The result is kept in
    // the form state so the rebuild on submit sees the same element.
    if (!$form_state->has('webform_composite_settings_sub_element')) {
      $staged = $this->getRequest()->request->get('webform_composite_settings_staged');
      $staged = is_string($staged) ? Json::decode($staged) : NULL;
      $form_state->set('webform_composite_settings_sub_element', $this->applyStagedRow(
        $parent_element['#element'][$composite_key],
        is_array($staged) ? $staged : []
      ));
    }
    $this->subElement = $form_state->get('webform_composite_settings_sub_element');

    // Mirrors WebformUiElementFormBase::buildForm() so the element plugin sees
    // the form tree it expects.
    $form['#parents'] = [];
    $form['properties'] = ['#parents' => ['properties']];
    $subform_state = SubformState::createForSubform($form['properties'], $form, $form_state);
    $subform_state->set('element', $this->subElement);
    $form['properties'] = $this->getWebformElementPlugin()->buildConfigurationForm($form['properties'], $subform_state);

    CompositeSubElementUi::filterSettingsGroups($form['properties']);
    // Runs after the groups are hidden, so that tabs left with nothing visible
    // in them are dropped rather than rendered empty.
    CompositeSubElementUi::scopeTabs($form['properties'], $key . '-' . $composite_key);

    // Element plugins lay some settings out side by side using classes that
    // only webform_ui styles, so without its library those inputs stack.
    // @see \Drupal\webform\Plugin\WebformElementBase::getFormInlineContainer()
    // @see \Drupal\webform_ui\Form\WebformUiElementFormBase::buildForm()
    $form['#attached']['library'][] = 'webform_ui/webform_ui';
    $form['#attached']['library'][] = 'webform/webform.form';
    $form['#attached']['library'][] = 'webform_composite_settings/tabs';

    $form['#prefix'] = '<div id="webform-composite-settings-sub-element-form">';
    $form['#suffix'] = '</div>';
    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -100,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply settings'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::submitAjax',
        'wrapper' => 'webform-composite-settings-sub-element-form',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $subform_state = SubformState::createForSubform($form['properties'], $form, $form_state);
    $this->getWebformElementPlugin()->validateConfigurationForm($form, $subform_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $subform_state = SubformState::createForSubform($form['properties'], $form, $form_state);
    $properties = $this->getWebformElementPlugin()->getConfigurationFormProperties($form, $subform_state);

    $form_state->set('webform_composite_settings_staged_row', $this->buildStagedRow($properties));

    if ($this->getRequest()->isXmlHttpRequest()) {
      // The Ajax callback hands the result to the row; nothing to persist.
      return;
    }

    // Without JavaScript there is no row to hand the result to, so fall back
    // to saving the webform directly and returning to the composite's form.
    // This path skips webform_ui's elements validator, so strip what it would
    // have rejected rather than rely on the runtime strip alone.
    $parent_element = $this->webform->getElementDecoded($this->compositeElementKey);
    $parent_element['#element'][$this->subElementKey] = WebformElementHelper::removeIgnoredProperties($properties);
    $this->webform->setElementProperties($this->compositeElementKey, $parent_element);
    $this->webform->save();

    $this->messenger()->addStatus($this->t('Settings for %key saved.', ['%key' => $this->subElementKey]));
    $form_state->setRedirectUrl(Url::fromRoute('entity.webform_ui.element.edit_form', [
      'webform' => $this->webform->id(),
      'key' => $this->compositeElementKey,
    ]));
  }

  /**
   * Ajax callback: closes the dialog and stages the result in the table row.
   */
  public function submitAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      return $response->addCommand(new ReplaceCommand('#webform-composite-settings-sub-element-form', $form));
    }

    $staged = $form_state->get('webform_composite_settings_staged_row');
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new StageCompositeRowCommand($staged['key'], $staged['fields'], $staged['options'], $staged['custom']));

    return $response;
  }

  /**
   * Overlays a table row's current values onto the saved sub-element.
   *
   * The row carries the same data the composite table would submit: the type
   * select, the options widget, the hidden inputs for the properties the table
   * collects itself, and the custom properties YAML holding everything else.
   *
   * The row arrives in the request body, so it is treated as untrusted: the
   * type must be one Webform allows in a composite, and the properties Webform
   * refuses to store (render and form callbacks among them) are stripped
   * before the element is used to build anything.
   *
   * @param array $sub_element
   *   The sub-element as last saved, with hash prefixed properties.
   * @param array $row
   *   The row's current input values, keyed by input name.
   *
   * @return array
   *   The sub-element to configure.
   */
  protected function applyStagedRow(array $sub_element, array $row): array {
    if (!$row) {
      return $sub_element;
    }

    // Everything the row holds outside its own inputs travels as YAML; it
    // replaces the saved extras wholesale, since that is how the table would
    // store it. Type and options start from their saved values and are then
    // read from the table's own inputs below.
    // addPrefix() works on keys, hence the flip first: ['#type' => 0, ...].
    $staged = array_intersect_key($sub_element, WebformArrayHelper::addPrefix(array_flip(self::TABLE_OWNED)));
    $staged += WebformArrayHelper::addPrefix($this->decodeYaml($row['custom'] ?? NULL));

    if (!empty($row['type']) && $this->isAllowedType($row['type'])) {
      $staged['#type'] = $row['type'];
    }

    // The options widget is a select of predefined option sets, where the
    // empty value means "custom" and the YAML editor beside it applies. A set
    // name the select does not offer is ignored, as the select itself would.
    // @see \Drupal\webform\Element\WebformElementOptions::CUSTOM_OPTION
    if (!empty($row['options_select']) && $this->isKnownOptionSet($row['options_select'])) {
      $staged['#options'] = $row['options_select'];
    }
    elseif ($options = $this->decodeYaml($row['options_custom'] ?? NULL)) {
      $staged['#options'] = $options;
    }

    foreach (CompositeSubElementUi::TABLE_PROPERTIES_MOVED as $name) {
      if (!isset($row[$name]) || $row[$name] === '') {
        continue;
      }
      $staged['#' . $name] = ($name === 'required') ? (bool) $row[$name] : $row[$name];
    }
    if (empty($staged['#required'])) {
      unset($staged['#required']);
    }

    return WebformElementHelper::removeIgnoredProperties($staged);
  }

  /**
   * Checks that a type from the request may be used for a sub-element.
   *
   * @param string $type
   *   The element type.
   *
   * @return bool
   *   TRUE if the type is a known element Webform allows inside a composite.
   */
  protected function isAllowedType($type): bool {
    return is_string($type)
      && $this->elementManager->hasDefinition($type)
      && WebformCompositeBase::isSupportedElementType($type);
  }

  /**
   * Checks that a predefined option set named by the request exists.
   *
   * @param mixed $id
   *   The option set id from the row's select.
   *
   * @return bool
   *   TRUE if a webform options entity with that id exists.
   */
  protected function isKnownOptionSet($id): bool {
    return is_string($id) && $this->optionsStorage->load($id) !== NULL;
  }

  /**
   * Splits submitted properties into what each of the row's inputs expects.
   *
   * @param array $properties
   *   The hash prefixed properties returned by the element plugin, with the
   *   defaults already stripped.
   *
   * @return array
   *   An array with the row 'key', its 'fields' keyed by input name, the
   *   'options' for the options widget ('select' and 'custom'), and the
   *   'custom' properties YAML.
   */
  protected function buildStagedRow(array $properties): array {
    // Anything Webform would refuse to store must not be handed to the row.
    $properties = WebformArrayHelper::removePrefix(WebformElementHelper::removeIgnoredProperties($properties));

    $fields = [];
    foreach (CompositeSubElementUi::TABLE_PROPERTIES_MOVED as $name) {
      $value = $properties[$name] ?? '';
      // The table's required input is a hidden field standing in for a
      // checkbox, so it wants '1' or nothing.
      $fields[$name] = ($name === 'required') ? (!empty($value) ? '1' : '') : (string) $value;
    }

    // A string names a predefined option set; an array is a custom list.
    $options = ['select' => '', 'custom' => ''];
    if (!empty($properties['options'])) {
      if (is_string($properties['options'])) {
        $options['select'] = $properties['options'];
      }
      elseif (is_array($properties['options'])) {
        $options['custom'] = WebformYaml::encode($properties['options']);
      }
    }

    $custom = array_diff_key($properties, array_flip(CompositeSubElementUi::TABLE_PROPERTIES_MOVED), array_flip(self::TABLE_OWNED));

    return [
      'key' => $this->subElementKey,
      'fields' => $fields,
      'options' => $options,
      'custom' => $custom ? WebformYaml::encode($custom) : '',
    ];
  }

  /**
   * Decodes a row's YAML input, treating anything unusable as empty.
   *
   * @param mixed $yaml
   *   The raw input value.
   *
   * @return array
   *   The decoded array, or an empty array.
   */
  protected function decodeYaml($yaml): array {
    if (!is_string($yaml) || trim($yaml) === '') {
      return [];
    }
    try {
      $decoded = Yaml::decode($yaml);
    }
    catch (\Exception) {
      return [];
    }
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * {@inheritdoc}
   */
  public function isNew(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getWebform(): WebformInterface {
    return $this->webform;
  }

  /**
   * {@inheritdoc}
   */
  public function getWebformElementPlugin(): WebformElementInterface {
    return $this->elementManager->getElementInstance($this->subElement, $this->webform);
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): array {
    return $this->subElement;
  }

  /**
   * {@inheritdoc}
   */
  public function getKey(): string {
    return $this->subElementKey;
  }

  /**
   * {@inheritdoc}
   */
  public function getParentKey(): string {
    return $this->compositeElementKey;
  }

}
