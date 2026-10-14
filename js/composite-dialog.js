/**
 * @file
 * Opens the sub-element settings dialog and stages its result in the table.
 *
 * The dialog never saves the webform. Opening it sends the row's current
 * values along, so unsaved edits are what the dialog shows; applying it writes
 * the result back into the row's inputs and marks the row. The values are
 * persisted when the composite element's own form is saved.
 *
 * @see \Drupal\webform_composite_settings\Ajax\StageCompositeRowCommand
 * @see \Drupal\webform_composite_settings\CompositeSubElementUi::addRowSettingsLinks()
 */

(function (Drupal, once) {
  /**
   * Row inputs the table collects itself: the title, and the hidden ones.
   *
   * Kept in step with the PHP constant of the same purpose.
   *
   * @see \Drupal\webform_composite_settings\CompositeSubElementUi::ROW_PROPERTIES
   */
  const FIELDS = ['title', 'placeholder', 'description', 'help', 'required'];

  /**
   * Name suffixes of the row's options widget inputs.
   *
   * The select names a predefined option set, or is empty for a custom list
   * held as YAML in the editor beside it.
   *
   * @see \Drupal\webform\Element\WebformElementOptions
   */
  const OPTIONS_SELECT = '[options][options]';
  const OPTIONS_CUSTOM = '[options][custom][options]';

  /**
   * Returns the name every input in the row starts with.
   *
   * Set server side from the key input's name; the key input itself is the
   * fallback, being the one input whose position is fixed.
   */
  function rowName(cell) {
    if (cell.dataset.webformCompositeSettingsRowName) {
      return cell.dataset.webformCompositeSettingsRowName;
    }
    const key = cell.querySelector('input[name$="[key]"]');
    return key ? key.name.replace(/\[key\]$/, '') : null;
  }

  /**
   * Finds one of a row's inputs by its exact name.
   */
  function input(cell, suffix) {
    const prefix = rowName(cell);
    if (prefix === null) {
      return null;
    }
    return cell.querySelector('[name="' + CSS.escape(prefix + suffix) + '"]');
  }

  /**
   * Returns the CodeMirror editor attached to a textarea, if any.
   *
   * CodeMirror inserts its wrapper right after the textarea it replaces and
   * keeps the editor instance on that wrapper. The sibling is tried first;
   * the parent is searched in case something else was inserted between them.
   */
  function editor(textarea) {
    if (!textarea || !textarea.parentNode) {
      return null;
    }
    let wrapper = textarea.nextElementSibling;
    if (!wrapper || !wrapper.classList.contains('CodeMirror')) {
      wrapper = textarea.parentNode.querySelector(':scope > .CodeMirror');
    }
    return wrapper && wrapper.CodeMirror ? wrapper.CodeMirror : null;
  }

  /**
   * Reads a textarea's current text, preferring its editor if it has one.
   */
  function readText(textarea) {
    const cm = editor(textarea);
    return cm ? cm.getValue() : textarea.value;
  }

  /**
   * Sets an input's value and lets change listeners know about it.
   */
  function setValue(element, value) {
    element.value = value;
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /**
   * Sets a textarea's text, and its editor's too when it has one.
   */
  function setText(textarea, value) {
    setValue(textarea, value);
    const cm = editor(textarea);
    if (cm) {
      cm.setValue(value);
    }
  }

  /**
   * Reads a row's current, possibly unsaved, values.
   */
  function readRow(cell) {
    const values = {};
    ['key', 'type'].concat(FIELDS).forEach(function (name) {
      const element = input(cell, '[' + name + ']');
      if (element) {
        values[name] = element.value;
      }
    });

    const custom = input(cell, '[custom]');
    if (custom) {
      values.custom = readText(custom);
    }

    const optionsSelect = input(cell, OPTIONS_SELECT);
    if (optionsSelect) {
      values.options_select = optionsSelect.value;
    }
    const optionsCustom = input(cell, OPTIONS_CUSTOM);
    if (optionsCustom) {
      values.options_custom = readText(optionsCustom);
    }

    return values;
  }

  /**
   * Flags a row as changed since the composite element was last saved.
   */
  function markChanged(cell) {
    (cell.closest('tr') || cell).classList.add(
      'webform-composite-settings-row--changed',
    );

    const link = cell.querySelector('.webform-composite-settings-link');
    if (!link || link.querySelector('.webform-composite-settings-row-marker')) {
      return;
    }

    // The asterisk is for sighted users; assistive technology gets the words.
    const marker = document.createElement('span');
    marker.className = 'webform-composite-settings-row-marker';
    marker.setAttribute('aria-hidden', 'true');
    marker.textContent = ' *';

    const label = document.createElement('span');
    label.className = 'visually-hidden';
    label.textContent = ' ' + Drupal.t('(changed, not yet saved)');

    link.appendChild(marker);
    link.appendChild(label);
    link.title = Drupal.t('Changed. Save this element to keep it.');
  }

  Drupal.behaviors.webformCompositeSettingsDialog = {
    attach(context) {
      once(
        'webform-composite-settings-dialog',
        '.webform-composite-settings-link',
        context,
      ).forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          const cell = link.closest('[data-webform-composite-settings-row]');

          Drupal.ajax({
            url: link.href,
            dialogType: 'modal',
            dialog: JSON.parse(link.dataset.dialogOptions || '{}'),
            progress: { type: 'fullscreen' },
            // Sent as POST data, so the form seeds itself from the table
            // rather than from the last saved configuration.
            submit: {
              webform_composite_settings_staged: JSON.stringify(
                cell ? readRow(cell) : {},
              ),
            },
          }).execute();
        });
      });
    },
  };

  /**
   * Ajax command: writes dialog results back into the matching table row.
   */
  Drupal.AjaxCommands.prototype.webformCompositeSettingsStageRow = function (
    ajax,
    response,
  ) {
    const cell = document.querySelector(
      '[data-webform-composite-settings-row="' +
        CSS.escape(response.key) +
        '"]',
    );
    if (!cell) {
      return;
    }

    Object.keys(response.fields).forEach(function (name) {
      const element = input(cell, '[' + name + ']');
      if (element) {
        setValue(element, response.fields[name]);
      }
    });

    if (response.options) {
      const optionsSelect = input(cell, OPTIONS_SELECT);
      if (optionsSelect) {
        // Changing the select also lets #states show or hide the editor.
        setValue(optionsSelect, response.options.select);
      }
      const optionsCustom = input(cell, OPTIONS_CUSTOM);
      if (optionsCustom) {
        setText(optionsCustom, response.options.custom);
      }
    }

    const custom = input(cell, '[custom]');
    if (custom) {
      setText(custom, response.custom);
    }

    markChanged(cell);
  };
})(Drupal, once);
