<?php

namespace Drupal\webform_composite_settings;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\webform_ui\Form\WebformUiElementFormInterface;

/**
 * Adapts the custom composite UI to defer settings to the sub-element form.
 *
 * Webform's custom composite builder only exposes eight properties per
 * sub-element, so anything else (a date maximum, say) has to be hand-written as
 * YAML. This class strips the properties that the sub-element settings form now
 * owns out of that table and gives each row a link to that form instead.
 */
class CompositeSubElementUi {

  /**
   * Sub-element properties the table holds in inputs of its own.
   *
   * The settings form reads these from the row when it opens and writes its
   * result back into them, so the row and the form never disagree. Its
   * JavaScript relies on this same list.
   *
   * @see \Drupal\webform_composite_settings\Form\CompositeSubElementForm
   * @see js/composite-dialog.js
   */
  public const ROW_PROPERTIES = [
    'title',
    'placeholder',
    'description',
    'help',
    'required',
  ];

  /**
   * Row properties the settings form owns outright.
   *
   * These stay in the table as hidden inputs rather than being removed, so
   * their stored values survive a save of the composite. The others remain
   * editable in the table: 'key' and 'type' define the structure of the
   * composite; 'options' because Webform rejects a composite whose
   * options-bearing sub-element has no options before the settings form is
   * ever reachable; and 'title' because Webform requires one of every
   * sub-element, it is the label the table is read by, and it is the one
   * setting worth typing without opening the form.
   *
   * @see \Drupal\webform\Element\WebformElementComposite::validateWebformElementComposite()
   */
  public const ROW_PROPERTIES_HIDDEN = [
    'placeholder',
    'description',
    'help',
    'required',
  ];

  /**
   * Settings that do not apply to an element nested in a composite.
   *
   * Each entry is the path of a group or input within the plugin's
   * configuration form, so that only that exact element is matched and never
   * a nested input that happens to share its name.
   *
   * Every group not listed here stays visible in the settings form. In
   * particular, Conditions and Access are shown because Webform honours both
   * on sub-elements: #states are applied while the composite is processed,
   * and the create/update access rules are applied when each sub-element is
   * built through WebformElementManager::buildElement(), so restricting a
   * sub-element by role does hide it on the form. Only the 'view' access rule
   * goes unchecked, because the composite formats its values without
   * consulting sub-element access.
   *
   * @see \Drupal\webform\Plugin\WebformElementBase::form()
   * @see \Drupal\webform\Plugin\WebformElementBase::buildConfigurationFormTabs()
   * @see \Drupal\webform\Plugin\WebformElementBase::prepare()
   * @see \Drupal\webform\Element\WebformCompositeBase::processWebformCompositeElementsRecursive()
   */
  protected const HIDDEN_SETTINGS_GROUPS = [
    // Administration: admin title and notes label elements in the build UI,
    // which lists top level elements only.
    ['admin'],
    // Submission display: a composite formats its values using its own
    // #format and never consults the sub-element's.
    // @see \Drupal\webform\Plugin\WebformElement\WebformCompositeBase::formatCompositeHtmlItems()
    ['display'],
    // Cardinality: Webform rejects multi-value composite sub-elements. The
    // widget that sets #multiple sits in the Element settings group; the group
    // named 'multiple' only holds its secondary settings (header, limits,
    // sorting), so both have to go.
    ['element', 'multiple'],
    ['multiple'],
    // Flex: webform_ui hides this unless the parent element is a flexbox, and
    // a composite never is.
    // @see \Drupal\webform_ui\Form\WebformUiElementFormBase::buildForm()
    ['flex'],
  ];

  /**
   * Process callback for the 'webform_element_composite' form element.
   *
   * Runs after WebformElementComposite::processWebformElementComposite().
   */
  public static function processCompositeTable(array &$element, FormStateInterface $form_state, array &$complete_form) {
    if (!isset($element['elements']['#element'])) {
      return $element;
    }
    $columns = &$element['elements']['#element'];

    // Replace, rather than hide, the moved fields. A nested element with
    // '#access' => FALSE loses its value inside the multiple-value table, while
    // a 'hidden' element round trips correctly.
    // @see \Drupal\webform\Element\WebformElementComposite::processWebformElementComposite()
    foreach (['settings', 'labels'] as $group) {
      foreach (static::ROW_PROPERTIES_HIDDEN as $property) {
        if (isset($columns[$group][$property])) {
          $columns[$group][$property] = ['#type' => 'hidden'];
        }
      }
    }

    // The custom properties YAML is how every other setting travels through
    // the table, so it has to stay in the row, but the settings form is the
    // one place to edit it. Webform already renders it as a hidden input for
    // users without 'edit webform source'; do the same for everyone.
    if (isset($columns['settings']['custom'])) {
      $columns['settings']['custom'] = ['#type' => 'hidden'];
    }

    // Every leaf is flattened to the row level regardless of which container it
    // sits in, so the now-empty Labels column can be folded into Settings
    // without changing where any value is submitted.
    // @see \Drupal\webform\Element\WebformMultiple::setElementRowParentsRecursive()
    if (isset($columns['labels'])) {
      foreach (Element::children($columns['labels']) as $child_key) {
        $columns['settings'][$child_key] = $columns['labels'][$child_key];
      }
      unset($columns['labels']);
    }

    // The title is the one visible input folded in, and it reads best right
    // under the key it labels rather than after the options widget.
    if (isset($columns['settings']['key'], $columns['settings']['title'])) {
      $columns['settings']['key']['#weight'] = -10;
      $columns['settings']['title']['#weight'] = -9;
    }

    // The rows do not exist yet: WebformMultiple clones this single definition
    // once per row while processing. The settings link is per row, so it has to
    // wait until those rows carry their own names.
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof WebformUiElementFormInterface
      && $form_object->getWebform()) {
      $element['#webform_composite_settings_context'] = [
        'webform' => $form_object->getWebform()->id(),
        // NULL while the composite is being added: it has no key until it is
        // saved, and nothing saved for the dialog to look up either way.
        'key' => $form_object->getKey() ?: NULL,
      ];
      $element['#after_build'][] = [static::class, 'addRowSettingsLinks'];
      $element['#attached']['library'][] = 'webform_composite_settings/dialog';
    }

    return $element;
  }

  /**
   * After build: adds a settings link to each row of the table.
   *
   * Every row gets one, saved or not: the dialog is opened with the row's
   * current values, so a row that was never saved has as much to configure as
   * one that was. What differs is only how much of the URL can be built here.
   * A row's key is whatever is typed into it when the table is built, so the
   * link carries it when there is one; a row added since then is linked
   * without it, and the dialog reads the key from the row when it is opened.
   */
  public static function addRowSettingsLinks(array $element, FormStateInterface $form_state) {
    $context = $element['#webform_composite_settings_context'] ?? NULL;
    if (!$context || !isset($element['elements']['items'])) {
      return $element;
    }

    foreach (Element::children($element['elements']['items']) as $delta) {
      // Checked before taking a reference, so a missing cell is not created.
      if (!isset($element['elements']['items'][$delta]['settings']['key'])) {
        continue;
      }
      $cell = &$element['elements']['items'][$delta]['settings'];

      // Lets the dialog's JavaScript find this row to read its current values
      // from and to write its results back to. The value is the name every
      // input in the row starts with, so each one can be addressed by its
      // exact name rather than by a suffix that some other input might one day
      // share. Unlike the key, it is fixed for the life of the row.
      // @see js/composite-dialog.js
      $cell['#attributes']['data-webform-composite-settings-row'] = preg_replace('/\[key\]$/', '', $cell['key']['#name'] ?? '');

      // The row's key only goes in the URL after the composite's own: both are
      // optional path parameters, and a URL cannot skip the first of them. A
      // composite being added has no key, so its rows are linked bare and the
      // dialog takes their keys from the row values it is opened with.
      $route_parameters = ['webform' => $context['webform']];
      $key = static::rowKey($element['elements']['items'][$delta]);
      if ($context['key'] !== NULL) {
        $route_parameters['key'] = $context['key'];
        if ($key !== NULL) {
          $route_parameters['composite_key'] = $key;
        }
      }

      // Deliberately not a 'use-ajax' link: the module's own handler opens the
      // dialog so it can send the row's unsaved values along with the request.
      $cell['webform_composite_settings_link'] = [
        '#type' => 'link',
        '#title' => t('Sub-element settings'),
        '#url' => Url::fromRoute('entity.webform_ui.element.composite_form', $route_parameters),
        '#attributes' => [
          'class' => [
            'button',
            'button--small',
            'webform-composite-settings-link',
          ],
          'data-dialog-options' => Json::encode([
            'width' => 880,
            'classes' => ['ui-dialog' => 'webform-composite-settings-dialog'],
          ]),
        ],
        '#weight' => 100,
      ];
    }

    return $element;
  }

  /**
   * Returns the key currently typed into a table row, or NULL if it has none.
   *
   * @param array $row
   *   The row's render array.
   *
   * @return string|null
   *   The key, or NULL if the row has no key input or it is empty.
   */
  protected static function rowKey(array $row): ?string {
    if (!isset($row['settings']['key'])) {
      return NULL;
    }
    $key = $row['settings']['key']['#value'] ?? $row['settings']['key']['#default_value'] ?? NULL;
    return is_string($key) && $key !== '' ? $key : NULL;
  }

  /**
   * Makes the settings form's tabs safe to render inside a dialog.
   *
   * Webform names every tab pane with a fixed id ('webform-tab--general' and
   * so on) and initialises Tabby with a document wide selector, so a tabbed
   * form opened over an element form binds to that form's panes instead of its
   * own and stops working. Renaming the panes per sub-element and taking the
   * 'webform-tabs' class off the wrapper keeps webform's behaviour away from
   * this markup, which is then driven by this module's own initialiser.
   *
   * @param array $form
   *   The built configuration form.
   * @param string $namespace
   *   A string unique to this sub-element, used to qualify the ids.
   *
   * @see \Drupal\webform\Utility\WebformFormHelper::buildTabs()
   */
  public static function scopeTabs(array &$form, $namespace) {
    if (!isset($form['tabs'])) {
      return;
    }

    $renamed = [];
    foreach (Element::children($form) as $key) {
      if (!str_starts_with($key, 'tab_')) {
        continue;
      }

      // A tab whose every member has been hidden would render as an empty
      // pane, so drop it along with its link below.
      $has_visible_member = FALSE;
      foreach (Element::children($form) as $child_key) {
        if (($form[$child_key]['#group'] ?? NULL) === $key
          && ($form[$child_key]['#access'] ?? TRUE) !== FALSE) {
          $has_visible_member = TRUE;
          break;
        }
      }

      $old_id = $form[$key]['#attributes']['id'] ?? NULL;
      if (!$has_visible_member || !$old_id) {
        unset($form[$key]);
        continue;
      }

      $renamed[$old_id] = Html::getId($old_id . '--' . $namespace);
      $form[$key]['#attributes']['id'] = $renamed[$old_id];
    }

    // Point each remaining link at its renamed pane, dropping links whose tab
    // is gone and closing the gaps the removals leave in the tab order.
    $items = [];
    $index = 0;
    foreach ($form['tabs']['items']['#items'] as $item) {
      $fragment = $item['#url']->getOption('fragment');
      if (!isset($renamed[$fragment])) {
        continue;
      }
      $item['#url'] = Url::fromRoute('<none>', [], ['fragment' => $renamed[$fragment]]);
      $item['#attributes']['data-tab-index'] = $index++;
      $items[] = $item;
    }

    // A single tab is just a heading over the whole form, so show the panes
    // plainly instead.
    if (count($items) < 2) {
      unset($form['tabs']);
      return;
    }

    $form['tabs']['items']['#items'] = $items;
    $form['tabs']['items']['#attributes']['id'] = Html::getId('webform-composite-settings-tabs-' . $namespace);
    $form['tabs']['#attributes']['class'] = ['webform-composite-settings-tabs'];
  }

  /**
   * Hides settings that do not apply inside a composite.
   *
   * Each denylisted path is matched exactly, then denied all the way down,
   * because the form builder does not pass '#access' on to children; it merely
   * skips rendering them. Left with access of their own, nested inputs would
   * still be validated, and Webform's cardinality widget reads a value it
   * assumes came from a visible input and writes '#multiple: 0' back.
   *
   * The hidden elements keep their stored values because an inaccessible
   * element still submits its default value, so hiding one never silently
   * drops a property that was set in YAML.
   */
  public static function filterSettingsGroups(array &$form) {
    foreach (static::HIDDEN_SETTINGS_GROUPS as $parents) {
      if (NestedArray::keyExists($form, $parents)) {
        $element = &NestedArray::getValue($form, $parents);
        static::denyAccessRecursive($element);
      }
    }
  }

  /**
   * Sets '#access' to FALSE on an element and on every element beneath it.
   */
  protected static function denyAccessRecursive(array &$element) {
    $element['#access'] = FALSE;
    foreach (Element::children($element) as $child_key) {
      if (is_array($element[$child_key])) {
        static::denyAccessRecursive($element[$child_key]);
      }
    }
  }

}
