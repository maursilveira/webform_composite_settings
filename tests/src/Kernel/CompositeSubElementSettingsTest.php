<?php

namespace Drupal\Tests\webform_composite_settings\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform_composite_settings\CompositeSubElementUi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests configuring custom composite sub-elements through their own form.
 *
 * @group webform_composite_settings
 */
#[Group('webform_composite_settings')]
#[RunTestsInSeparateProcesses]
class CompositeSubElementSettingsTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path_alias',
    'webform',
    'webform_ui',
    'webform_composite_settings',
  ];

  /**
   * The webform holding the composite under test.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected $webform;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    // Webform generates sample values while building an element's settings
    // form, and the date elements need core's date formats to do that.
    // @see \Drupal\webform\Plugin\WebformElement\DateBase::getTestValues()
    $this->installConfig(['system', 'webform']);

    // Webform hides the Access settings group from anyone without this
    // permission, on the regular element form too, so run as the kind of user
    // who would actually open this dialog.
    // @see \Drupal\webform\Plugin\WebformElementBase::form()
    // 'edit webform source' is what makes Webform show the custom properties
    // editor in the table, so having it proves the module hides it regardless.
    $this->setCurrentUser($this->createUser(['administer webform', 'edit webform source']));

    $this->webform = Webform::create([
      'id' => 'composite_settings_test',
      'title' => 'Composite settings test',
    ]);
    $this->webform->setElements([
      'appointment' => [
        '#type' => 'webform_custom_composite',
        '#title' => 'Appointment',
        '#element' => [
          'when' => ['#type' => 'date', '#title' => 'When'],
          'note' => ['#type' => 'textfield', '#title' => 'Note'],
          'choice' => [
            '#type' => 'radios',
            '#title' => 'Choice',
            '#options' => ['a' => 'A', 'b' => 'B'],
          ],
        ],
      ],
    ]);
    $this->webform->save();
  }

  /**
   * Submits the sub-element form the way the dialog does.
   *
   * @param string $composite_key
   *   The sub-element key.
   * @param array $values
   *   Property values, without the hash prefix.
   * @param bool $ajax
   *   Whether to submit as the dialog would (TRUE) or as a plain page (FALSE).
   * @param array|null $row
   *   Row input values to send along, as the dialog's JavaScript would.
   * @param string $parent_key
   *   The composite element's key, or '' for one that is still being added.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The submitted form state.
   */
  protected function submitSubElementForm($composite_key, array $values, $ajax = TRUE, ?array $row = NULL, $parent_key = 'appointment') {
    if ($ajax) {
      \Drupal::request()->headers->set('X-Requested-With', 'XMLHttpRequest');
    }
    else {
      \Drupal::request()->headers->remove('X-Requested-With');
    }
    if ($row === NULL) {
      \Drupal::request()->request->remove('webform_composite_settings_staged');
    }
    else {
      \Drupal::request()->request->set('webform_composite_settings_staged', Json::encode($row));
    }

    $form_state = new FormState();
    $form_state->setValues(['properties' => $values]);
    $form_state->setProgrammed(TRUE);
    $form_state->setProgrammedBypassAccessCheck(FALSE);

    \Drupal::formBuilder()->submitForm(
      '\Drupal\webform_composite_settings\Form\CompositeSubElementForm',
      $form_state,
      $this->webform,
      $parent_key,
      $composite_key
    );

    $this->container->get('entity_type.manager')->getStorage('webform')->resetCache();
    $this->webform = Webform::load('composite_settings_test');

    return $form_state;
  }

  /**
   * Builds the sub-element form, optionally seeded with a row's values.
   *
   * @param string $composite_key
   *   The sub-element key.
   * @param array|null $row
   *   Row input values to send along, as the dialog's JavaScript would.
   * @param string $parent_key
   *   The composite element's key, or '' for one that is still being added.
   *
   * @return array
   *   The built form.
   */
  protected function buildSubElementForm($composite_key, ?array $row = NULL, $parent_key = 'appointment') {
    if ($row === NULL) {
      \Drupal::request()->request->remove('webform_composite_settings_staged');
    }
    else {
      \Drupal::request()->request->set('webform_composite_settings_staged', Json::encode($row));
    }

    return \Drupal::formBuilder()->getForm(
      '\Drupal\webform_composite_settings\Form\CompositeSubElementForm',
      $this->webform,
      $parent_key,
      $composite_key
    );
  }

  /**
   * Finds the first form input with the given key, however deeply nested.
   */
  protected function findInput(array $form, $name) {
    foreach (Element::children($form) as $key) {
      if ($key === $name && isset($form[$key]['#type'])) {
        return $form[$key];
      }
      if (is_array($form[$key]) && ($found = $this->findInput($form[$key], $name))) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * Tests that applying settings stages them in the row and saves nothing.
   */
  public function testSubmitStagesTheRowInsteadOfSaving(): void {
    $form_state = $this->submitSubElementForm('when', [
      'title' => 'When',
      'date_date_min' => '2026-01-01',
      'date_date_max' => '2026-12-31',
      'required' => 1,
    ]);
    $this->assertEmpty($form_state->getErrors());

    // The webform itself is untouched until the composite's form is saved.
    $this->assertSame(
      ['#type' => 'date', '#title' => 'When'],
      $this->webform->getElementDecoded('appointment')['#element']['when']
    );

    $staged = $form_state->get('webform_composite_settings_staged_row');

    // What the table collects itself goes to its own inputs, with required
    // shaped for the hidden input that stands in for the checkbox.
    $this->assertSame('When', $staged['fields']['title']);
    $this->assertSame('1', $staged['fields']['required']);
    $this->assertSame('', $staged['fields']['placeholder']);

    // Everything else rides in the custom properties YAML, except what the
    // table owns outright.
    $custom = Yaml::decode($staged['custom']);
    $this->assertSame('2026-01-01', $custom['date_date_min']);
    $this->assertSame('2026-12-31', $custom['date_date_max']);
    foreach (['type', 'options', 'title', 'required'] as $owned_elsewhere) {
      $this->assertArrayNotHasKey($owned_elsewhere, $custom);
    }

    // A hidden group must not leak values either. The form builder does not
    // pass #access to children, so unless the whole group is denied, Webform's
    // cardinality widget validates as if visible and writes back 'multiple: 0'.
    $this->assertArrayNotHasKey('multiple', $custom);
  }

  /**
   * Tests that the form starts from the row's unsaved values, not from config.
   *
   * Without this, reopening a sub-element before saving the composite would
   * show its saved settings and, on apply, silently discard the earlier edit.
   */
  public function testUnsavedRowValuesSeedTheForm(): void {
    $form = $this->buildSubElementForm('when', [
      'key' => 'when',
      'type' => 'date',
      'title' => 'Unsaved title',
      'required' => '1',
      'custom' => "date_date_max: '2030-06-30'",
    ]);

    $this->assertSame('Unsaved title', $this->findInput($form, 'title')['#default_value']);
    $this->assertSame('2030-06-30', $this->findInput($form, 'date_date_max')['#default_value']);
    $this->assertNotEmpty($this->findInput($form, 'required')['#default_value']);

    // With nothing staged, the saved configuration is the starting point.
    $form = $this->buildSubElementForm('when');
    $this->assertSame('When', $this->findInput($form, 'title')['#default_value']);
    $this->assertEmpty($this->findInput($form, 'date_date_max')['#default_value']);
  }

  /**
   * Tests that a hostile row payload cannot smuggle a type or a callback in.
   *
   * The row arrives in the request body from anyone with webform edit access.
   * A type Webform does not allow in a composite must be ignored, and the
   * properties Webform refuses to store must be stripped at every exit: the
   * form itself, the values handed back to the row, and the fallback save.
   */
  public function testStagedPayloadIsSanitised(): void {
    $hostile = [
      'key' => 'when',
      'type' => 'webform_computed_twig',
      'title' => 'When',
      'custom' => "element_validate:\n  - system_cron\npre_render:\n  - phpinfo\ndate_date_max: '2030-01-01'",
    ];

    // The disallowed type gives way to the saved one, so the date settings are
    // still what gets built, seeded from the harmless part of the payload.
    $form = $this->buildSubElementForm('when', $hostile);
    $this->assertSame('2030-01-01', $this->findInput($form, 'date_date_max')['#default_value']);

    // An unknown type is treated the same way.
    $form = $this->buildSubElementForm('when', ['type' => 'no_such_element'] + $hostile);
    $this->assertNotNull($this->findInput($form, 'date_date_max'));

    // Callbacks never reach the row on apply.
    $form_state = $this->submitSubElementForm('when', ['title' => 'When', 'date_date_max' => '2030-01-01'], TRUE, $hostile);
    $this->assertEmpty($form_state->getErrors());
    $custom = Yaml::decode($form_state->get('webform_composite_settings_staged_row')['custom']);
    $this->assertSame('2030-01-01', $custom['date_date_max']);
    $this->assertArrayNotHasKey('element_validate', $custom);
    $this->assertArrayNotHasKey('pre_render', $custom);

    // Nor the webform, on the non-JavaScript fallback.
    $this->submitSubElementForm('when', ['title' => 'When'], FALSE, $hostile);
    $saved = $this->webform->getElementDecoded('appointment')['#element']['when'];
    $this->assertSame('date', $saved['#type']);
    $this->assertArrayNotHasKey('#element_validate', $saved);
    $this->assertArrayNotHasKey('#pre_render', $saved);
  }

  /**
   * Tests that a plain, non-Ajax submit still saves the webform.
   *
   * Without JavaScript there is no table row to stage into, so the form falls
   * back to the direct save and returns to the composite's own form.
   */
  public function testPlainSubmitFallsBackToSaving(): void {
    $form_state = $this->submitSubElementForm('when', [
      'title' => 'When',
      'date_date_max' => '2026-12-31',
    ], FALSE);
    $this->assertEmpty($form_state->getErrors());

    $sub_element = $this->webform->getElementDecoded('appointment')['#element']['when'];
    $this->assertSame('2026-12-31', $sub_element['#date_date_max']);

    // The redirect was recorded, but getRedirect() hides it for programmatic
    // submissions, so read it as a real request would.
    $form_state->setProgrammed(FALSE);
    $this->assertSame('entity.webform_ui.element.edit_form', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Tests that options are edited in the dialog and staged to the table widget.
   *
   * The table's options widget is a select of predefined option sets, where
   * the empty value means the custom YAML beside it applies. The dialog must
   * read whichever the row holds and hand its result back in the same shape.
   */
  public function testOptionsAreEditedInTheDialog(): void {
    // The options list is offered like any other setting.
    $form = $this->buildSubElementForm('choice');
    $this->assertNotFalse($form['properties']['options']['options']['#access'] ?? TRUE);
    $this->assertSame(['a' => 'A', 'b' => 'B'], $form['properties']['options']['options']['#default_value']);

    // An unsaved custom list in the row seeds the form.
    $form = $this->buildSubElementForm('choice', [
      'key' => 'choice',
      'type' => 'radios',
      'options_select' => '',
      'options_custom' => "x: X\ny: Y",
    ]);
    $this->assertSame(['x' => 'X', 'y' => 'Y'], $form['properties']['options']['options']['#default_value']);

    // So does an unsaved predefined set.
    $form = $this->buildSubElementForm('choice', [
      'key' => 'choice',
      'type' => 'radios',
      'options_select' => 'yes_no',
      'options_custom' => '',
    ]);
    $this->assertSame('yes_no', $form['properties']['options']['options']['#default_value']);

    // A set name the select does not offer is ignored, as the select itself
    // would ignore it, so the saved options stay.
    $form = $this->buildSubElementForm('choice', [
      'key' => 'choice',
      'type' => 'radios',
      'options_select' => 'no_such_set',
      'options_custom' => '',
    ]);
    $this->assertSame(['a' => 'A', 'b' => 'B'], $form['properties']['options']['options']['#default_value']);

    // A row that says nothing about options keeps the saved ones.
    $form = $this->buildSubElementForm('choice', ['key' => 'choice', 'type' => 'radios']);
    $this->assertSame(['a' => 'A', 'b' => 'B'], $form['properties']['options']['options']['#default_value']);

    // Applying a predefined set targets the select and clears the editor, and
    // options never leak into the custom properties YAML.
    $form_state = $this->submitSubElementForm('choice', [
      'title' => 'Choice',
      'options' => ['options' => 'yes_no'],
      'options_display' => 'side_by_side',
    ]);
    $this->assertEmpty($form_state->getErrors());
    $staged = $form_state->get('webform_composite_settings_staged_row');
    $this->assertSame(['select' => 'yes_no', 'custom' => ''], $staged['options']);
    $custom = Yaml::decode($staged['custom']);
    $this->assertSame('side_by_side', $custom['options_display']);
    $this->assertArrayNotHasKey('options', $custom);
  }

  /**
   * Tests that stored settings reach the rendered webform.
   *
   * Storing a property is not the same as it taking effect, so assert on the
   * built submission form rather than on config alone.
   */
  public function testSettingsAreAppliedToTheSubmissionForm(): void {
    $element = $this->webform->getElementDecoded('appointment');
    $element['#element']['when'] += ['#date_date_max' => '2026-12-31', '#required' => TRUE];
    $element['#element']['note'] += ['#maxlength' => 40];
    $this->webform->setElementProperties('appointment', $element);
    $this->webform->save();

    $composite = $this->webform->getSubmissionForm()['elements']['appointment'];

    $this->assertSame('2026-12-31', $composite['#element']['when']['#date_date_max']);
    $this->assertTrue($composite['#element']['when']['#required']);
    $this->assertSame(40, $composite['#element']['note']['#maxlength']);
  }

  /**
   * Tests that settings groups meaningless inside a composite are hidden.
   */
  public function testInapplicableGroupsAreHidden(): void {
    $form = $this->buildSubElementForm('when');

    foreach (['admin', 'display', 'multiple', 'flex'] as $group) {
      if (isset($form['properties'][$group])) {
        $this->assertFalse(
          $form['properties'][$group]['#access'],
          sprintf("The '%s' group should not be reachable inside a composite.", $group)
        );
      }
    }

    // The widget that actually sets #multiple lives in the Element settings
    // group, not in the 'multiple' group, and must be hidden explicitly.
    $this->assertFalse($form['properties']['element']['multiple']['#access']);

    // These must stay reachable, because Webform honours them on sub-elements:
    // #states are applied during composite processing, and the create/update
    // access rules are applied when each sub-element is built. Hiding either
    // would remove working functionality.
    // @see \Drupal\webform\Element\WebformCompositeBase::processWebformComposite()
    // @see \Drupal\webform\Plugin\WebformElementBase::prepare()
    foreach (['conditional_logic', 'access'] as $group) {
      $this->assertArrayHasKey($group, $form['properties']);
      $this->assertNotFalse($form['properties'][$group]['#access'] ?? TRUE, $group);
    }

    // The type specific settings must still be there, otherwise the whole
    // point of the form is gone.
    $this->assertArrayHasKey('date_date_max', $form['properties']['date']['date_container']);
  }

  /**
   * Tests that the settings form's tabs cannot collide with the parent form's.
   *
   * The tab containers use fixed ids and the Tabby init uses a document wide
   * selector, so tabs inside the dialog would bind to the element form behind
   * it and stop working unless renamed and initialised separately.
   *
   * @see \Drupal\webform\Utility\WebformFormHelper::buildTabs()
   */
  public function testSettingsFormHasNoCollidingTabs(): void {
    $parent = \Drupal::formBuilder()->getForm(
      '\Drupal\webform_ui\Form\WebformUiElementEditForm',
      $this->webform,
      'appointment'
    );
    $modal = $this->buildSubElementForm('when');

    $pane_ids = function (array $form) {
      $ids = [];
      foreach (Element::children($form['properties']) as $key) {
        if (str_starts_with($key, 'tab_')) {
          $ids[$key] = $form['properties'][$key]['#attributes']['id'];
        }
      }
      return $ids;
    };

    $parent_ids = $pane_ids($parent);
    $modal_ids = $pane_ids($modal);

    // Both forms are tabbed, but no pane id may appear in both: Tabby resolves
    // a tab link's fragment against the whole document.
    $this->assertNotEmpty($parent_ids);
    $this->assertNotEmpty($modal_ids);
    $this->assertSame([], array_intersect($parent_ids, $modal_ids));

    // Webform's behaviour keys off this class, and must not claim the dialog's
    // markup, since its initialiser is not scoped to it.
    $this->assertSame(['webform-composite-settings-tabs'], $modal['properties']['tabs']['#attributes']['class']);

    // Conditions and Access both apply to composite sub-elements, so their
    // tabs must survive; a tab whose members are all hidden would be dropped.
    $this->assertArrayHasKey('tab_conditions', $modal_ids);
    $this->assertArrayHasKey('tab_access', $modal_ids);

    // Every remaining link must point at a pane that is actually present, and
    // the tab order must have no gaps where dropped tabs used to be.
    $indexes = [];
    foreach ($modal['properties']['tabs']['items']['#items'] as $item) {
      $this->assertContains($item['#url']->getOption('fragment'), $modal_ids);
      $indexes[] = $item['#attributes']['data-tab-index'];
    }
    $this->assertSame(range(0, count($indexes) - 1), $indexes);
  }

  /**
   * Tests that alters aimed at the regular element form apply to this one.
   *
   * Modules extend element settings through
   * hook_form_webform_ui_element_form_alter(), and some depend on it to clean
   * up after themselves. webform_validation adds component checkboxes and
   * strips their unchecked "key: 0" entries with a validator added that way;
   * left unstripped, they were stored on the client's own webform.
   */
  public function testElementFormAltersApply(): void {
    // The form builder takes the form state by reference, so it has to be a
    // variable rather than the result of a chained call.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$this->webform, 'appointment', 'when']);
    \Drupal::formBuilder()->buildForm('\Drupal\webform_composite_settings\Form\CompositeSubElementForm', $form_state);
    $this->assertSame('webform_ui_element_form', $form_state->getBuildInfo()['base_form_id']);

    if (!$this->container->get('extension.list.module')->exists('webform_validation')) {
      $this->markTestSkipped('webform_validation is not in the codebase; the base form id assertion above still holds.');
    }
    $this->enableModules(['webform_validation']);

    // The component checkboxes are on the form; left untouched, as a site
    // builder editing a date maximum would leave them, core's checkbox
    // processing submits every one of them as 0.
    $this->assertSame('checkboxes', $this->findInput($this->buildSubElementForm('when'), 'equal__components')['#type']);

    $form_state = $this->submitSubElementForm('when', ['title' => 'When']);
    $this->assertSame([], array_map('strval', $form_state->getErrors()));

    // With the zeros stripped there is nothing left for the YAML to carry.
    $custom = Yaml::decode($form_state->get('webform_composite_settings_staged_row')['custom']) ?: [];
    $this->assertArrayNotHasKey('equal__components', $custom);
    $this->assertArrayNotHasKey('some_of_several__components', $custom);
  }

  /**
   * Tests that the table alteration is registered exactly once.
   *
   * The hook lives in a #[Hook] class, with a #[LegacyHook] shim in the
   * .module file for Drupal 10. Were the shim to lose its attribute, cores
   * that discover both would run the hook twice and process the table twice.
   */
  public function testTableProcessCallbackIsRegisteredOnce(): void {
    $info = $this->container->get('plugin.manager.element_info')->getInfo('webform_element_composite');

    $registrations = array_filter($info['#process'], function ($callback) {
      return is_array($callback) && ($callback[1] ?? NULL) === 'processCompositeTable';
    });
    $this->assertCount(1, $registrations);
  }

  /**
   * Tests that the composite table defers settings but keeps structure.
   */
  public function testCompositeTableDefersSettings(): void {
    $form = \Drupal::formBuilder()->getForm(
      '\Drupal\webform_ui\Form\WebformUiElementEditForm',
      $this->webform,
      'appointment'
    );
    $columns = $form['properties']['composite']['element']['elements']['#element'];

    // Structural fields stay editable in the table.
    $this->assertSame('textfield', $columns['settings']['key']['#type']);
    $this->assertSame('select', $columns['settings']['type']['#type']);
    $this->assertSame('webform_element_options', $columns['settings']['options']['#type']);

    // So does the title: Webform requires one of every sub-element, and it is
    // what the rows are read by. It keeps Webform's own required validation.
    $this->assertSame('textfield', $columns['settings']['title']['#type']);
    $this->assertTrue($columns['settings']['title']['#required']);

    // Everything else is deferred to the sub-element form, but must still post
    // back so that saving the composite does not discard it. That includes the
    // custom properties YAML, even for a user allowed to edit webform source.
    foreach (['placeholder', 'description', 'help', 'required', 'custom'] as $property) {
      $this->assertSame('hidden', $columns['settings'][$property]['#type'], $property);
    }
    $this->assertArrayNotHasKey('labels', $columns);
  }

  /**
   * Tests that each row of a saved composite links to its own settings form.
   */
  public function testRowsLinkToTheSettingsForm(): void {
    $form = \Drupal::formBuilder()->getForm(
      '\Drupal\webform_ui\Form\WebformUiElementEditForm',
      $this->webform,
      'appointment'
    );
    $items = $form['properties']['composite']['element']['elements']['items'];

    $found = [];
    foreach (Element::children($items) as $delta) {
      $cell = $items[$delta]['settings'] ?? [];
      $link = $cell['webform_composite_settings_link'] ?? NULL;
      if (($link['#type'] ?? NULL) !== 'link') {
        continue;
      }
      $key = $link['#url']->getRouteParameters()['composite_key'];
      $found[] = $key;

      // The route name is what marks this as a webform admin page, which in
      // turn drives the admin form styling.
      // @see \Drupal\webform\WebformRequest::isWebformAdminRoute()
      $this->assertSame('entity.webform_ui.element.composite_form', $link['#url']->getRouteName());

      // The dialog is opened by the module's own handler, which needs to find
      // the row again to read from and write back to it. The row is marked
      // with the name its inputs share, which unlike the key never changes.
      $this->assertSame(
        preg_replace('/\\[key\\]$/', '', $cell['key']['#name']),
        $cell['#attributes']['data-webform-composite-settings-row']
      );
      $this->assertStringEndsWith("[items][$delta]", $cell['#attributes']['data-webform-composite-settings-row']);
      $this->assertNotContains('use-ajax', $link['#attributes']['class']);
    }

    sort($found);
    $this->assertSame(['choice', 'note', 'when'], $found);
  }

  /**
   * Tests that rows get a settings link whether or not they are saved.
   *
   * The link carries what is known when the table is built: the composite's
   * key once it has one, and the row's key once it is typed. A row added over
   * Ajax has no key yet and a composite being added has none at all, so those
   * parts are left out of the URL for the dialog to fill in from the row.
   */
  public function testUnsavedRowsGetSettingsLinks(): void {
    $row = function (int $delta, string $key) {
      return [
        'settings' => [
          'key' => [
            '#type' => 'textfield',
            '#name' => "properties[element][elements][items][$delta][key]",
            '#value' => $key,
          ],
        ],
      ];
    };
    $element = [
      '#webform_composite_settings_context' => [
        'webform' => $this->webform->id(),
        'key' => 'appointment',
      ],
      'elements' => [
        'items' => [$row(0, 'when'), $row(1, 'extra'), $row(2, '')],
      ],
    ];

    $items = CompositeSubElementUi::addRowSettingsLinks($element, new FormState())['elements']['items'];

    foreach ([0, 1, 2] as $delta) {
      $this->assertSame('link', $items[$delta]['settings']['webform_composite_settings_link']['#type'], "Row $delta");
    }
    $parameters = function (int $delta) use (&$items) {
      return $items[$delta]['settings']['webform_composite_settings_link']['#url']->getRouteParameters();
    };
    // Saved and unsaved keys alike go in the URL; only an empty one does not.
    $this->assertSame('when', $parameters(0)['composite_key']);
    $this->assertSame('extra', $parameters(1)['composite_key']);
    $this->assertArrayNotHasKey('composite_key', $parameters(2));
    $this->assertSame('appointment', $parameters(2)['key']);
    $this->assertSame(
      'properties[element][elements][items][2]',
      $items[2]['settings']['#attributes']['data-webform-composite-settings-row']
    );

    // A composite being added has no key, and the row's key cannot follow a
    // missing one in the URL, so its rows are linked bare whether they have a
    // key or not. Generating the URL is what fails otherwise.
    $element['#webform_composite_settings_context']['key'] = NULL;
    $items = CompositeSubElementUi::addRowSettingsLinks($element, new FormState())['elements']['items'];
    foreach ([0, 1, 2] as $delta) {
      $this->assertSame(['webform' => $this->webform->id()], $parameters($delta), "Row $delta");
    }
    \Drupal::service('router.builder')->rebuild();
    $this->assertStringEndsWith('/composite-sub-element', $items[0]['settings']['webform_composite_settings_link']['#url']->toString());
  }

  /**
   * Tests that the add form's table gets the settings links as well.
   *
   * The composite has no key while it is being added, which used to leave its
   * rows with no way to open the dialog at all.
   */
  public function testAddFormRowsLinkToTheSettingsForm(): void {
    $form = \Drupal::formBuilder()->getForm(
      '\Drupal\webform_ui\Form\WebformUiElementAddForm',
      $this->webform,
      NULL,
      NULL,
      'webform_custom_composite'
    );
    $items = $form['properties']['composite']['element']['elements']['items'];
    $this->assertNotEmpty(Element::children($items));

    foreach (Element::children($items) as $delta) {
      $link = $items[$delta]['settings']['webform_composite_settings_link'];
      $this->assertSame('link', $link['#type'], "Row $delta");
      $this->assertSame(
        ['webform' => $this->webform->id()],
        $link['#url']->getRouteParameters(),
        "Row $delta"
      );
    }
  }

  /**
   * Tests that a row that was never saved opens from its own values.
   *
   * Whether the composite is saved without that row or not saved at all, the
   * dialog has nothing in configuration to start from, so the row has to be
   * all it needs: its key, when the URL has none, and its type.
   */
  public function testUnsavedRowOpensFromItsOwnValues(): void {
    $row = [
      'key' => 'extra',
      'type' => 'date',
      'title' => 'Extra',
      'custom' => "date_date_max: '2031-01-01'",
    ];

    // A new row in a saved composite, linked before its key was typed.
    $form = $this->buildSubElementForm('', $row);
    $this->assertSame('Extra', $this->findInput($form, 'title')['#default_value']);
    $this->assertSame('2031-01-01', $this->findInput($form, 'date_date_max')['#default_value']);

    // The submit request carries only the form's inputs and may have to build
    // the form again, so the row has to post back with it under the name the
    // dialog sent it as. Without that, the rebuild has no key and no type.
    $this->assertSame('hidden', $form['webform_composite_settings_staged']['#type']);
    $this->assertSame($row, Json::decode($form['webform_composite_settings_staged']['#value']));

    // A row of a composite that is still being added.
    $form = $this->buildSubElementForm('', $row, '');
    $this->assertSame('Extra', $this->findInput($form, 'title')['#default_value']);
    $this->assertSame('2031-01-01', $this->findInput($form, 'date_date_max')['#default_value']);

    // Applying stages the result the same way as for a saved row.
    $form_state = $this->submitSubElementForm('', ['title' => 'Extra', 'date_date_max' => '2032-01-01'], TRUE, $row, '');
    $this->assertEmpty($form_state->getErrors());
    $staged = $form_state->get('webform_composite_settings_staged_row');
    $this->assertSame('Extra', $staged['fields']['title']);
    $this->assertSame('2032-01-01', Yaml::decode($staged['custom'])['date_date_max']);
    $this->assertArrayNotHasKey('extra', $this->webform->getElementDecoded('appointment')['#element']);
  }

  /**
   * Tests that the key the row holds wins over the one in the URL.
   *
   * The link is built with the key the row had when the table was built, and
   * the row may have been renamed since. The URL's key is only what is left
   * without JavaScript, when no row is sent at all.
   */
  public function testRowKeyWinsOverUrlKey(): void {
    $row = ['key' => 'when_renamed', 'type' => 'date', 'title' => 'When'];

    // Applied as the dialog would, the result is staged for the row, and the
    // form reports the row's key as its own.
    $form_state = $this->submitSubElementForm('when', ['title' => 'When'], TRUE, $row);
    $this->assertEmpty($form_state->getErrors());
    $this->assertSame('when_renamed', $form_state->getFormObject()->getKey());

    // Without a row, the URL's key is all there is.
    $form = $this->buildSubElementForm('when');
    $this->assertSame('When', $this->findInput($form, 'title')['#default_value']);
  }

  /**
   * Tests that a visit with nothing to configure is a 404.
   *
   * A plain visit sends no row values, so an unsaved key has nothing behind
   * it; and a row that has not chosen a type yet cannot have a form built.
   */
  public function testNothingToConfigureIsNotFound(): void {
    $not_found = 0;
    $attempts = [
      // An unsaved key with no row values.
      ['nope', NULL, 'appointment'],
      // No key anywhere.
      ['', ['type' => 'date'], 'appointment'],
      // A key that is not one Webform would accept.
      ['', ['key' => 'Not A Key', 'type' => 'date'], 'appointment'],
      // A row with no type yet.
      ['', ['key' => 'extra', 'type' => ''], ''],
      // A composite key that is not a custom composite.
      ['when', NULL, 'missing'],
    ];
    foreach ($attempts as [$composite_key, $row, $parent_key]) {
      try {
        $this->buildSubElementForm($composite_key, $row, $parent_key);
      }
      catch (NotFoundHttpException) {
        $not_found++;
      }
    }
    $this->assertSame(count($attempts), $not_found);
  }

  /**
   * Tests that stored settings survive a re-save of the composite element.
   *
   * The table no longer shows most properties, so they have to travel back
   * through hidden inputs and the per row custom properties field.
   */
  public function testSettingsSurviveCompositeReSave(): void {
    $element = $this->webform->getElementDecoded('appointment');
    $element['#element']['when'] += ['#date_date_max' => '2026-12-31', '#required' => TRUE];
    $this->webform->setElementProperties('appointment', $element);
    $this->webform->save();

    $form = \Drupal::formBuilder()->getForm(
      '\Drupal\webform_ui\Form\WebformUiElementEditForm',
      $this->webform,
      'appointment'
    );
    $items = $form['properties']['composite']['element']['elements']['items'];

    $row = NULL;
    foreach (Element::children($items) as $delta) {
      $cell = $items[$delta]['settings'] ?? NULL;
      $key = $cell['key']['#value'] ?? $cell['key']['#default_value'] ?? NULL;
      if ($key === 'when') {
        $row = $cell;
      }
    }
    $this->assertNotNull($row, "The 'when' row should be present in the table.");

    // Supported properties ride along in the row's own inputs.
    $this->assertSame('When', $row['title']['#default_value']);
    $this->assertTrue($row['required']['#default_value']);
    // Anything the table has no column for ends up in custom properties.
    $this->assertStringContainsString('date_date_max', (string) $row['custom']['#default_value']);
  }

}
