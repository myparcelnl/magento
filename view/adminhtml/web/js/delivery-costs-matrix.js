define(
    function () {
        'use strict';

        return function DeliveryCostsMatrix(options) {
            const model = {
                // Set up initial properties
                initialize: function (options) {
                    this.options = options;
                    this.matrixElement = document.getElementById('delivery-costs-matrix');
                    this.hiddenInputElement = document.getElementById('myparcelnl_magento_general_matrix_delivery_costs');

                    // Initialize translations and condition options
                    this.translations = JSON.parse(this.options.getTranslations);
                    this.conditionOptionsList = [
                        {value: '', text: this.translations['Select a condition']},
                        {value: 'carrier_name', text: this.translations['Carrier name']},
                        {value: 'country', text: this.translations['Country']},
                        {value: 'package_type', text: this.translations['Package type']},
                        {value: 'maximum_weight (in grams)', text: this.translations['Maximum weight (in grams)']},
                        {value: 'country_part_of', text: this.translations['Country part of']},
                    ];

                    // This is the first place where the JSON is being parsed, if the JSON is invalid,
                    // this will be shown to the user and the matrix will not be rendered
                    try {
                        // Get existing rule data from hidden input and sort it by name
                        this.ruleData = this.hiddenInputElement.value
                            ? JSON.parse(this.hiddenInputElement.value).sort((a, b) => (a.name || '').localeCompare(b.name || ''))
                            : [];
                    } catch (e) {
                        const errorMessage = document.createElement('p');
                        errorMessage.className = 'error-message';
                        errorMessage.textContent = this.translations['Invalid JSON in textarea'] + ' ' + e;
                        this.matrixElement.appendChild(errorMessage);
                        return;
                    }


                    // Convert conditions from object format to array format for UI display
                    this.ruleData.forEach(rule => {
                        if (rule.conditions && !Array.isArray(rule.conditions)) {
                            rule.conditions = Object.entries(rule.conditions).map(([type, value]) => ({ type, value }));
                        } else if (!rule.conditions) {
                            rule.conditions = [];
                        }
                    });

                    // Track open/closed state of each rule
                    this.openRules = {};
                    this.render();

                    //initialize scoping
                    const scopingInput = document.getElementById('myparcelnl_magento_general_matrix_delivery_costs_ui_inherit');
                    if (scopingInput !== null) {
                        this.updateScoping(scopingInput.checked);
                        scopingInput.addEventListener('change', () => this.updateScoping(scopingInput.checked));
                    }

                    return this;
                },

                // Create the matrix UI
                render: function() {
                    this.matrixElement.innerHTML = '';
                    // If there are no rules, show an empty message
                    if (this.ruleData.length === 0) {
                        const emptyMessage = document.createElement('p');
                        emptyMessage.textContent = this.translations['No rules defined. Click Add rule to create a new rule.'];
                        this.matrixElement.appendChild(emptyMessage);
                    }
                    // Render each existing rule
                    this.ruleData.forEach((rule, index) => {
                        const ruleElement = this.createRuleElement(index, rule);
                        this.matrixElement.appendChild(ruleElement);
                    });

                    const addButton = document.createElement('button');
                    addButton.type = 'button';
                    addButton.className = 'add-rule-button';
                    addButton.textContent = this.translations['Add Rule'];
                    addButton.addEventListener('click', () => this.addRule());
                    this.matrixElement.appendChild(addButton);

                    const showTextareaContainer = document.createElement('div');
                    showTextareaContainer.className = 'show-textarea-container';
                    const showTextareaLabel = document.createElement('label');
                    showTextareaLabel.textContent = this.translations['Show or hide JSON textarea'];
                    const showTextareaInput = document.createElement('input');
                    showTextareaInput.id = 'show-textarea-input';
                    showTextareaInput.type = 'checkbox';
                    showTextareaLabel.htmlFor = 'show-textarea-input';

                    const jsonTextarea = document.getElementById('myparcelnl_magento_general_matrix_delivery_costs');

                    // Hide the textarea by default
                    jsonTextarea.style.display = 'none';

                    showTextareaInput.addEventListener('change', (e) => {
                        jsonTextarea.style.display = e.target.checked ? 'block' : 'none';
                    });

                    showTextareaContainer.appendChild(showTextareaLabel);
                    showTextareaContainer.appendChild(showTextareaInput);


                    this.matrixElement.appendChild(showTextareaContainer);
                },

                // Create a rule element with its conditions
                createRuleElement: function (ruleIndex, rule) {
                    const ruleId = 'rule-' + ruleIndex;
                    const container = document.createElement('div');
                    container.id = ruleId;
                    container.className = 'rule-form';
                    container.dataset.ruleIndex = ruleIndex;

                    const header = document.createElement('div');
                    header.className = 'rule-header';
                    header.innerHTML = `
                        <div class="form-field">
                            <label for="rule-name-${ruleId}">${this.translations["Rule name"]}</label>
                            <input id="rule-name-${ruleId}" class="rule-name" type="text" value="${rule.name || ''}">
                        </div>
                        <div class="form-field">
                            <label for="price-${ruleId}">${this.translations["Price"]}</label>
                            <input id="price-${ruleId}" class="price" type="number" value="${rule.price || ''}">
                        </div>
                        <a class="display-rule-button">
                            <img class="display-rule-button-icon" src="${options.chevronDownIcon}" alt="${this.translations['Toggle conditions']}">
                        </a>
                        <a class="remove-rule-button">
                            <img src="${options.plusIcon}" alt="${this.translations['Remove rule']}" title="${this.translations['Remove rule']}">
                        </a>
                    `;

                    container.appendChild(header);

                    // Create a container for conditions
                    const conditionsContainer = document.createElement('div');
                    conditionsContainer.className = 'conditions-container';

                    // Only set display:none if openRules[ruleIndex] is not true (default closed)
                    if (!this.openRules[ruleIndex]) {
                        conditionsContainer.style.display = 'none';
                    } else {
                        conditionsContainer.style.display = 'block';
                    }

                    container.appendChild(conditionsContainer);

                    // Create condition rows
                    if (rule.conditions && Array.isArray(rule.conditions)) {
                        rule.conditions.forEach((condition, conditionIndex) => {
                            const conditionRow = this.createConditionRow(ruleIndex, conditionIndex, condition);
                            conditionsContainer.appendChild(conditionRow);
                        });
                    }

                    const addConditionButton = document.createElement('a');
                    addConditionButton.className = 'add-condition-button';
                    addConditionButton.innerHTML = `<img src="${options.plusIcon}" alt="${this.translations['Add condition']}" title="${this.translations['Add condition']}">`;
                    conditionsContainer.appendChild(addConditionButton);

                    // Add event listeners for rule header inputs and buttons
                    header.querySelector(`#rule-name-${ruleId}`).addEventListener('change', (e) => this.updateRuleField(ruleIndex, 'name', e.target.value));
                    header.querySelector(`#price-${ruleId}`).addEventListener('change', (e) => this.updateRuleField(ruleIndex, 'price', e.target.value));
                    header.querySelector('.remove-rule-button').addEventListener('click', () => this.removeRule(ruleIndex));
                    addConditionButton.addEventListener('click', () => this.addCondition(ruleIndex));
                    header.querySelector('.display-rule-button').addEventListener('click', () => {
                        // Toggle open/closed state
                        const isOpen = conditionsContainer.style.display === 'block';
                        conditionsContainer.style.display = isOpen ? 'none' : 'block';
                        header.querySelector('.display-rule-button-icon').src = !isOpen ? options.chevronUpIcon : options.chevronDownIcon;
                        this.openRules[ruleIndex] = !isOpen;
                    });

                    // Set correct chevron icon on render
                    header.querySelector('.display-rule-button-icon').src = (this.openRules[ruleIndex]) ? options.chevronUpIcon : options.chevronDownIcon;

                    return container;
                },

                // Update rule field values
                updateRuleField: function(ruleIndex, field, value) {
                    this.ruleData[ruleIndex][field] = value;
                    this.save();
                },

                // Add a new rule to the ruleData and re-render the matrix
                addRule: function () {
                    const newRule = {
                        name: this.translations["New rule"],
                        price: 0,
                        conditions: []
                    };
                    this.ruleData.push(newRule);
                    this.render();
                    this.save();
                },

                // Remove a rule from the ruleData and re-render the matrix
                removeRule: function (ruleIndex) {
                    this.ruleData.splice(ruleIndex, 1);
                    this.render();
                    this.save();
                },

                // Add a new condition to a specific rule and re-render the matrix
                addCondition: function (ruleIndex) {
                    if (!this.ruleData[ruleIndex].conditions) {
                        this.ruleData[ruleIndex].conditions = [];
                    }
                    this.ruleData[ruleIndex].conditions.push({ type: '', value: '' });
                    this.render();
                    this.save();
                },

                // Remove a condition from a specific rule and re-render the matrix
                removeCondition: function(ruleIndex, conditionIndex) {
                    this.ruleData[ruleIndex].conditions.splice(conditionIndex, 1);
                    this.render();
                    this.save();
                },

                // Create a condition row with its options, values and event listeners
                createConditionRow: function (ruleIndex, conditionIndex, condition) {
                    const conditionRow = document.createElement('div');
                    conditionRow.className = 'condition-row';
                    conditionRow.dataset.conditionIndex = conditionIndex;

                    const conditionOptionFormField = document.createElement('div');
                    conditionOptionFormField.className = 'form-field';

                    const conditionOptionLabel = document.createElement('label');
                    conditionOptionLabel.textContent = this.translations['Condition'] + ' ' + (conditionIndex + 1);
                    conditionOptionFormField.appendChild(conditionOptionLabel);

                    const conditionValueFormField = document.createElement('div');
                    conditionValueFormField.className = 'form-field';

                    const conditionValueLabel = document.createElement('label');
                    conditionValueLabel.textContent = this.translations['Value'];
                    conditionValueFormField.appendChild(conditionValueLabel);

                    const conditionOptions = document.createElement('select');

                    // Fill the condition with the available options
                    this.conditionOptionsList.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.text;
                        conditionOptions.appendChild(option);
                    });

                    // Set the selected condition type
                    conditionOptions.value = condition.type;

                    // Create the input for the condition value based on the type
                    let conditionValues;
                    // If the condition type is 'maximum_weight', use an input, otherwise use a select with the available values
                    if (condition.type === 'maximum_weight') {
                        conditionValues = document.createElement('input');
                        conditionValues.type = 'number';
                        conditionValues.value = condition.value;
                        conditionValues.addEventListener('change', (e) => this.updateCondition(ruleIndex, conditionIndex, 'value', e.target.value));
                    } else {
                        conditionValues = document.createElement('select');
                        this.setConditionValue(condition.type, conditionValues);
                        conditionValues.value = condition.value;
                        conditionValues.addEventListener('change', (e) => this.updateCondition(ruleIndex, conditionIndex, 'value', e.target.value));
                    }

                    const removeButton = document.createElement('a');
                    removeButton.className = 'remove-condition-button';
                    removeButton.innerHTML = `<img src="${options.plusIcon}" alt="${this.translations['Remove condition']}" title="${this.translations['Remove condition']}">`;

                    // Add event listeners for condition options and remove button
                    conditionOptions.addEventListener('change', (e) => {
                        this.updateCondition(ruleIndex, conditionIndex, 'type', e.target.value);
                        this.updateCondition(ruleIndex, conditionIndex, 'value', ''); // Reset value

                        // Create the new value input based on the selected type
                        const newType = e.target.value;
                        let newValueInput;

                        // If the new type is 'maximum_weight', create an input, otherwise create a select with the available values
                        if (newType === 'maximum_weight') {
                            newValueInput = document.createElement('input');
                            newValueInput.type = 'number';
                            newValueInput.value = '';
                            newValueInput.addEventListener('change', (ev) => this.updateCondition(ruleIndex, conditionIndex, 'value', ev.target.value));
                        } else {
                            newValueInput = document.createElement('select');
                            this.setConditionValue(newType, newValueInput);
                            newValueInput.value = '';
                            newValueInput.addEventListener('change', (ev) => this.updateCondition(ruleIndex, conditionIndex, 'value', ev.target.value));
                        }

                        // Replace the old value input with the new one
                        conditionValueFormField.replaceChild(newValueInput, conditionValueFormField.childNodes[1]);
                    });

                    removeButton.addEventListener('click', () => this.removeCondition(ruleIndex, conditionIndex));

                    conditionOptionFormField.appendChild(conditionOptions);
                    conditionValueFormField.appendChild(conditionValues);

                    conditionRow.appendChild(conditionOptionFormField);
                    conditionRow.appendChild(conditionValueFormField);
                    conditionRow.appendChild(removeButton);

                    return conditionRow;
                },

                // Update a specific condition's field value
                updateCondition: function(ruleIndex, conditionIndex, field, value) {
                    this.ruleData[ruleIndex].conditions[conditionIndex][field] = value;
                    this.save();
                },

                // Set the available values for a condition based on its type
                setConditionValue: function (conditionValue, valueSelect) {
                    // Clear existing options
                    valueSelect.innerHTML = '';

                    if (conditionValue === 'country') {
                        JSON.parse(options.countryCodes).forEach(countryCode => {
                            const option = document.createElement('option');
                            option.value = countryCode;
                            option.textContent = countryCode;
                            valueSelect.appendChild(option);
                        });
                    }

                    if (conditionValue === 'package_type') {
                        const packageTypes = JSON.parse(options.packageTypes);
                        Object.entries(packageTypes).forEach(([value, text]) => {
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = text;
                            valueSelect.appendChild(option);
                        });
                    }

                    if (conditionValue ==='carrier_name') {
                        const carriers = JSON.parse(options.carriers);
                        Object.entries(carriers).forEach(([value, text]) => {
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = text;
                            valueSelect.appendChild(option);
                        });
                    }

                    if (conditionValue === 'country_part_of') {
                        JSON.parse(options.countryParts).forEach(countryCode => {
                            const option = document.createElement('option');
                            option.value = countryCode;
                            option.textContent = countryCode;
                            valueSelect.appendChild(option);
                        });
                    }
                },

                // Update the scoping of the matrix, enabling/disabling inputs and hiding links/buttons
                updateScoping: function(scopingChecked) {
                    const hiddenScopeElement = document.getElementById('myparcelnl_magento_general_matrix_delivery_costs_inherit');
                    const hiddenTextarea = document.getElementById('myparcelnl_magento_general_matrix_delivery_costs')
                    hiddenScopeElement.checked = scopingChecked;
                    hiddenTextarea.disabled = scopingChecked;

                    // If scoping is checked, disable all inputs and hide links/buttons
                    if (scopingChecked) {
                        this.matrixElement.querySelectorAll('input, select, a, button').forEach(el => {
                            if (el.tagName === 'A' || el.tagName === 'BUTTON') {
                                el.style.display = 'none';
                            } else {
                                el.disabled = true;
                            }
                        })
                        return;
                    }

                    // If scoping is unchecked, enable all inputs and show links/buttons
                    this.matrixElement.querySelectorAll('input, select, a, button').forEach(el => {
                        if (el.tagName === 'A' || el.tagName === 'BUTTON') {
                            el.style.display = '';
                        } else {
                            el.disabled = false;
                        }
                    });
                },

                // Save the current rule data to the hidden input element
                save: function() {
                    this.hiddenInputElement.value = JSON.stringify(this.ruleData, null, 2);
                }
            };

            model.initialize(options);
            return model;
        };
    }
);
