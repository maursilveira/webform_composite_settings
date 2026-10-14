# Webform Composite Settings

Exposes the full element settings form for sub-elements inside Webform
“Custom composite” elements.

## Table of contents

* [Why this module exists](#why-this-module-exists)
* [Features](#features)
* [Requirements](#requirements)
* [Installation](#installation)
* [How it works](#how-it-works)
  * [Nothing is saved from the modal](#nothing-is-saved-from-the-modal)
  * [What the table shows](#what-the-table-shows)
  * [Settings groups](#settings-groups)
* [Compatibility](#compatibility)
* [Implementation notes](#implementation-notes)
* [Files](#files)
* [Running the tests](#running-the-tests)
* [Known limitations](#known-limitations)
* [Maintainers](#maintainers)

## Why this module exists

Webform’s Custom composite element is a quick way to group a few fields together, but its builder only lets you set a handful of properties on each sub-element: key, type, title, description, help, placeholder, options and required. If a sub-element needs anything more than that, say a date range, a number minimum, a maximum length or a condition, there is no form for it. You have to write the property as YAML in the “Custom properties” box, which is hidden from anyone without the “Edit webform source” permission. The Webform maintainers have chosen to keep the builder minimal, so this is not something a future Webform release will add ([#3008728](https://www.drupal.org/project/webform/issues/3008728)).

This module solves this. It adds a “Sub-element settings” button to every row in the composite’s Elements table. Clicking it opens the sub-element’s full configuration form in a modal, the same form Webform shows for a normal element of that type, with its General, Conditions, Validation, Access and Custom properties tabs and anything else the element type offers. The only settings left out are the ones that do nothing inside a composite: Administration, Submission display, Cardinality and Flex.

The modal does not save anything on its own. When you apply it, the values go back into the row, and they are stored with the composite when you save the element, just as if you had typed them into the table. The element plugin validates the input, so the modal catches the same mistakes the regular element form would.

## Features

- Every setting an element type supports, available on sub-elements without touching YAML.
- Conditions and access rules on individual sub-elements. Webform already honours them inside composites; it just had no way to set them.
- Edits that stay with the composite until you save it. Reopen a sub-element before saving and your pending changes are still there, and rows you have changed stay marked while you add or remove others.
- Settings on rows that are not saved yet. A sub-element just added to the table, or every sub-element of a composite you are still adding, can be configured before anything is saved.
- A title typed in the table carries into the modal, and a title set in the modal comes back to the table.
- The same alterations other modules make to the element form, including Webform Validation and Webform Simplify.
- A plain, JavaScript-free fallback that saves a saved sub-element directly. The modal needs JavaScript. If it is unavailable, the button opens the settings form as a normal page, and saving that page writes the sub-element straight to the webform instead of staging it in the table.
- Support for Drupal 10.3, 11 and 12.

## Requirements

* Drupal 10.3 or later, including Drupal 11 and 12.
* [Webform](https://www.drupal.org/project/webform) with its Webform UI
  submodule enabled.

## Installation

Install as you would any Drupal module:

```
composer require drupal/webform_composite_settings
drush en webform_composite_settings
```

There is nothing to configure. Once the module is enabled, every row of a
custom composite’s Elements table shows a “Sub-element settings” button,
whether the composite is being added or edited.

## How it works

Each row of the composite’s Elements table gets a “Sub-element settings” button
that opens the element plugin’s own configuration form in a modal. The form is
rendered unchanged rather than rebuilt: because each sub-element gets a form to
itself, its fields keep their native `properties[...]` names, so the plugin’s
`#states` selectors and its `validateConfigurationForm()` resolve exactly as
they do on a normal element form.

### Nothing is saved from the modal

The modal never saves the webform. Its result is staged in the composite’s own
form and persisted when that form is saved, exactly as if it had been typed
into the table:

* Opening the modal sends the row’s current values along with the request, so
  it always starts from what is in the table, saved or not. Reopening a
  sub-element before saving therefore shows the earlier edit rather than
  discarding it, and a row that has never been saved, or belongs to a
  composite that is still being added, can be configured like any other.
* Applying the modal closes it and writes the result back into the row’s
  inputs, then highlights the row and adds an asterisk to its button.
* Staged changes are ordinary unsaved form state: reloading or leaving the page
  loses them, the same as any other edit in the table.
* The highlight and asterisk live only in the page. The table’s own add and
  remove buttons rebuild it over Ajax, and the staged values survive that,
  since they are part of the form’s state; the page remembers the changed
  rows by their key and marks them again once the table is back.
* The values a row sends when the modal opens are treated as untrusted: an
  element type Webform does not allow in a composite is ignored in favour of
  the saved one, and the properties Webform refuses to store (render and form
  callbacks among them) are stripped on the way in, on the way back to the
  row, and before the non-JavaScript fallback saves.
* Without JavaScript there is no row to write to, so a plain submit saves the
  webform directly and returns to the composite’s form. Only a saved
  sub-element can be reached that way; an unsaved row has no values to send
  without JavaScript.

### What the table shows

The table keeps key, title, type and options visible, plus the button. Key
and type define the structure of the composite. Title stays because Webform
requires one of every sub-element and it is what the rows are read by, so it
can be typed without opening the modal; the modal starts from whatever the row
holds and writes its own title back into the row. Options stay because Webform
rejects an options-bearing sub-element with no options before the button would
ever be reachable; they are also editable in the modal, which writes them back
into the table’s widget (the predefined-set select, or the custom YAML beside
it).

Every other property, including the custom properties YAML, stays in the row as
a hidden input so it still posts back and survives a save of the composite. The
custom properties field is hidden even for users with `edit webform source`.

The button is disabled until the row has a key and a type, since the settings
form is built for an element of that type under that key. Nothing has to be
saved first.

### Settings groups

Every group of the plugin’s form is shown except those that do nothing inside a
composite. They are hidden rather than removed, so values already set in YAML
are preserved rather than dropped:

| Group | Why it is hidden |
|---|---|
| Administration | Admin title and notes label elements in the build UI, which lists top-level elements only. |
| Submission display | A composite formats its values with its own `#format` and never consults the sub-element’s. |
| Cardinality (multiple) | Webform rejects multi-value composite sub-elements. |
| Flex | webform_ui hides this unless the parent is a flexbox, and a composite never is. |

Conditions and Access are shown, because Webform honours both on sub-elements:
`#states` are applied during composite processing, and the create/update access
rules are applied when each sub-element is built. Two caveats on Access:

* The `view` rule is not enforced on sub-elements; the composite formats its
  values without consulting sub-element access.
* Webform itself hides the Access group from anyone without `administer webform`
  or `administer webform element access`, on the regular element form too. A
  user who does not see the tab is missing the permission, not the feature.

## Compatibility

The module declares `core_version_requirement: ^10.3 || ^11 || ^12`. Its hook
lives in a `#[Hook]` class, which Drupal 11.1 and later discover on their own.
For Drupal 10 the `.module` file keeps procedural functions that call into
that class; they are marked `#[LegacyHook]` so newer cores do not run both, and
the class is declared in `webform_composite_settings.services.yml` because
Drupal 10 does not register hook classes as services by itself. A kernel test
asserts the hook is registered exactly once. Once Drupal 10 support is dropped,
the `.module` file and the services file can both be deleted.

Upgrade Status reports nothing else for Drupal 12. The dialog still relies on
core’s jQuery UI based dialog (`core/drupal.dialog.ajax`), which is not
deprecated; the `dialogClass` option that is deprecated is deliberately not
used.

## Implementation notes

* **Route name.** `entity.webform_ui.element.composite_form` deliberately sits
  in webform_ui’s namespace. Webform decides whether a page is a “webform admin
  route” by pattern matching the route name, and that drives admin-only
  rendering such as collapsing long descriptions into help tooltips. Renaming
  the route changes the form’s layout. See
  `\Drupal\webform\WebformRequest::isWebformAdminRoute()`.
* **Route keys.** Both the composite’s key and the sub-element’s key are
  optional path parameters. A composite being added has no key yet, and a row
  added to the table has none until it is typed, after the button was
  rendered. The button carries whatever is known when the table is built, with
  one restriction: a URL can only leave out optional parameters at its end, so
  a row’s key is only put in the URL after the composite’s own, and the rows of
  a composite being added are linked bare. The dialog takes the sub-element
  key from the row values it is opened with whenever they carry one, since the
  row may have been renamed after its link was built; the URL’s key only
  counts without JavaScript, when no row is sent. The form starts from an
  empty element when nothing is saved under that key. A URL with neither a
  saved sub-element nor row values is a 404, and so is a row whose type is
  not chosen yet; the button is disabled in the table until the key and type
  are both filled.
* **Rebuild on submit.** Core only serves a submitted form from the form cache
  when it can; otherwise it builds the form again for the submit request, and
  that request carries only the form’s own inputs. The row values the dialog
  was opened with are therefore repeated inside the form as a hidden input
  under the same request parameter name, so a rebuild on submit starts from
  the same key and type as the first build did.
* **Changed rows.** The highlight and asterisk are page state. The table’s add
  and remove buttons replace its markup over Ajax, so `js/composite-dialog.js`
  keeps the keys of the rows changed since the last save and marks those rows
  again whenever the behaviour attaches; a changed row that is given a new
  key stays marked under the new one. The key is used because rows are
  renumbered when one is removed. The keys are kept per element form rather
  than per page: Webform opens that form in its own dialog and refreshes the
  build page over Ajax when it is saved, so a page-wide list would mark rows
  that have nothing pending the next time the form opens.
* **Title in the table.** The title is the one row property that is both
  visible in the table and present in the modal. The modal seeds its Title
  field from the row and writes its result back into the same input, so the
  two never diverge. Webform’s own required validation on the table’s title
  applies; the module no longer derives a title from the key.
* **Tabs.** Webform’s tab panes use fixed ids (`webform-tab--general` and so
  on) and its Tabby initialiser uses a document-wide selector, so tabs inside
  the dialog would bind to the element form behind it and stop working.
  `CompositeSubElementUi::scopeTabs()` renames the panes per sub-element,
  swaps the wrapper class so Webform’s behaviour ignores the markup, drops tabs
  whose members are all hidden, and `js/composite-tabs.js` initialises Tabby
  against the dialog’s own list. Tabby is CDN-hosted by Webform; if it fails to
  load, every pane stays visible and the form remains usable.
* **Dialog height.** A settings form is taller than most viewports, and a
  centred jQuery UI dialog taller than the screen pushes its own title bar off
  the top. `css/composite-dialog.css` caps the dialog and scrolls its content.
* **Theme-neutral colours.** The changed-row highlight and marker use no
  fixed colours: the tint is mixed from the theme’s current text colour and
  the marker inherits the button’s, so both follow any admin theme and colour
  scheme. Themes can override them through the
  `--webform-composite-settings-changed-background` and
  `--webform-composite-settings-marker-color` custom properties.
* **Row keys.** A row’s key is whatever is typed into it, saved or not. The
  row is never identified by it: `js/composite-dialog.js` remembers which row
  opened the dialog and writes the result back there, so a renamed or
  duplicated key cannot send the result to the wrong row. Duplicates are
  rejected by Webform when the composite is saved, as before.
* **Row write-back.** `js/composite-dialog.js` finds the row by the
  `data-webform-composite-settings-row` attribute, whose value is the name
  prefix shared by every input in the row, and addresses each input by its
  exact name built from that prefix. The attribute is set in
  `CompositeSubElementUi::addRowSettingsLinks()`. The custom properties and
  custom options inputs may be CodeMirror editors; the editor is updated too,
  otherwise it overwrites the textarea on submit.
* **Layout parity.** The modal attaches `webform_ui/webform_ui` and
  `webform/webform.form`, which the regular element form also loads; without
  them the side-by-side settings groups stack.
* **Base form id.** The form declares `webform_ui_element_form` as its base
  form id, so every `hook_form_webform_ui_element_form_alter()` runs on it as
  well. Modules that extend element settings depend on those alters to finish
  the job: webform_validation strips the unchecked `key: 0` entries of its
  component checkboxes with a validator added that way, and without it the
  modal stored them. webform_simplify’s per-element hiding applies to the
  modal for the same reason.

## Files

| File | Role |
|---|---|
| `src/Hook/WebformCompositeSettingsHooks.php` | Hook implementations: `hook_help()`, and `hook_element_info_alter()` adding the table process callback. |
| `webform_composite_settings.module` | Drupal 10 shim: `#[LegacyHook]` functions delegating to the hook class. |
| `webform_composite_settings.services.yml` | Registers the hook class as a service for Drupal 10. |
| `src/CompositeSubElementUi.php` | Alters the composite table (hidden inputs, title under the key, per-row button, row attribute), hides inapplicable groups, scopes the tabs. |
| `src/Form/CompositeSubElementForm.php` | The modal form: resolves the keys from the URL or the row, builds the plugin’s form, seeds it from the row, carries the row for the submit rebuild, stages the result, falls back to saving without JavaScript. |
| `src/Ajax/StageCompositeRowCommand.php` | Ajax command carrying the result back to the row that opened the modal. |
| `js/composite-dialog.js` | Enables the button once the row has a key and a type; opens the modal with the row’s values; applies the command to the row that opened it; marks changed rows and keeps them marked across table rebuilds. |
| `js/composite-tabs.js` | Initialises the dialog’s tabs. |
| `css/composite-dialog.css` | Dialog sizing, row highlight and marker. |
| `css/composite-tabs.css` | Webform’s tab spacing, repeated for the renamed wrapper. |

## Running the tests

From the Drupal root, with `SIMPLETEST_DB` pointing at a database the tests may
use:

```
vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/contrib/webform_composite_settings/tests
```

The kernel suite covers: the hook being registered once; staging instead of
saving; seeding the form from a row’s unsaved values, including options; the
non-JavaScript fallback; stored settings reaching the rendered submission form;
the hidden and the deliberately visible settings groups; tab ids not colliding
with the parent form; the table’s visible title and hidden inputs; the per-row
links on saved rows, unsaved rows and the add form, including that their URLs
generate; opening an unsaved row from its own values, with the row carried in
the form for the submit rebuild; the 404 cases; and settings surviving a
re-save of the composite.

The browser layer (opening the modal, the write-back, CodeMirror updates, the
button’s disabled state, the row marker and its survival across table
rebuilds, dialog sizing) is not covered and needs a manual check after changes
to the JavaScript or CSS.

## Known limitations

* Title and options remain editable in the table as well as in the modal, for
  the reasons given above.
* Without JavaScript, only a saved sub-element can be opened: an unsaved row
  has no way to send its key and type along with a plain link.
* Changed-row marks and staged values are page state. Reloading or leaving the
  page loses both, like any other unsaved edit in the table.
* Sub-element `view` access rules are not enforced; that is Webform’s composite
  formatting, not something this module changes.
* webform_simplify hides disabled tabs by matching their original fragment
  (`webform-tab--access` and so on). The modal has renamed its panes by the
  time that alter runs, so a tab disabled there would still show in the modal.

## Maintainers

* Mauricio Silveira ([maursilveira](https://www.drupal.org/u/maursilveira))
