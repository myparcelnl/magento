define(
    function () {
        'use strict';

        return function DeliveryCostsMatrix(options) {
            let model = {
                initialize: function (options) {
                    this.options = options;
                    this.matrixElement = document.getElementById('delivery-costs-matrix');
                    this.hiddenInputElement = document.getElementById('myparcelnl_magento_general_matrix_delivery_costs');
                    this.ruleData = this.hiddenInputElement.value ? JSON.parse(this.hiddenInputElement.value) : [];
                    // map ruleData conditions to array if they are not already
                    this.ruleData.forEach(rule => {
                        if (rule.conditions && !Array.isArray(rule.conditions)) {
                            rule.conditions = Object.entries(rule.conditions).map(([type, value]) => ({ type, value }));
                        }
                    });
                    this.ruleCounter = this.ruleData.length;
                    this.conditionOptionsList = [
                        {value: '', text: 'Select a condition...'},
                        {value: 'carrier_name', text: 'Carrier name'},
                        {value: 'country', text: 'Country'},
                        {value: 'package_type', text: 'Package type'},
                        {value: 'maximum_weight', text: 'Maximum weight'},
                        {value: 'country_part_of', text: 'Country part of'},
                    ];
                    // Track open/closed state of each rule
                    this.openRules = {};
                    this.render();
                    return this;
                },

                render: function() {
                    this.matrixElement.innerHTML = '';
                    if (this.ruleData.length === 0) {
                        const emptyMessage = document.createElement('p');
                        emptyMessage.textContent = 'No rules defined. Click "Add Rule" to create a new rule.';
                        this.matrixElement.appendChild(emptyMessage);
                    }
                    this.ruleData.forEach((rule, index) => {
                        const ruleElement = this.createRuleElement(index, rule);
                        this.matrixElement.appendChild(ruleElement);
                    });
                    let addButton = document.createElement('button');
                    addButton.type = 'button';
                    addButton.className = 'add-rule-button';
                    addButton.textContent = 'Add Rule';
                    addButton.addEventListener('click', () => this.addRule());
                    this.matrixElement.appendChild(addButton);
                },

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
                            <label for="rule-name-${ruleId}">Rule name</label>
                            <input id="rule-name-${ruleId}" class="rule-name" type="text" value="${rule.name || ''}">
                        </div>
                        <div class="form-field">
                            <label for="price-${ruleId}">Price</label>
                            <input id="price-${ruleId}" class="price" type="number" value="${rule.price || ''}">
                        </div>
                        <a class="display-rule-button">
                            <img class="display-rule-button-icon" src="${options.chevronDownIcon}" alt="Toggle conditions">
                        </a>
                        <a class="remove-rule-button">
                            <img src="${options.plusIcon}" alt="Remove rule" title="Remove rule">
                        </a>
                    `;

                    container.appendChild(header);

                    const conditionsContainer = document.createElement('div');
                    conditionsContainer.className = 'conditions-container';

                    // Only set display:none if openRules[ruleIndex] is not true (default closed)
                    if (!this.openRules[ruleIndex]) {
                        conditionsContainer.style.display = 'none';
                    } else {
                        conditionsContainer.style.display = 'block';
                    }

                    container.appendChild(conditionsContainer);

                    if (rule.conditions && Array.isArray(rule.conditions)) {
                        rule.conditions.forEach((condition, conditionIndex) => {
                            const conditionRow = this.createConditionRow(ruleIndex, conditionIndex, condition);
                            conditionsContainer.appendChild(conditionRow);
                        });
                    }

                    const conditionCount = conditionsContainer.querySelectorAll('.condition-row').length
                    const addConditionButton = document.createElement('a');
                    if (conditionCount < 5) {
                        addConditionButton.className = 'add-condition-button';
                        addConditionButton.innerHTML = `<img src="${options.plusIcon}" alt="Add condition" title="Add condition">`;
                        conditionsContainer.appendChild(addConditionButton);
                    }

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

                updateRuleField: function(ruleIndex, field, value) {
                    this.ruleData[ruleIndex][field] = value;
                    this.save();
                },

                addRule: function () {
                    const newRule = {
                        name: 'New Rule',
                        price: 0,
                        conditions: []
                    };
                    this.ruleData.push(newRule);
                    this.ruleCounter = this.ruleData.length;
                    this.render();
                    this.save();
                },

                removeRule: function (ruleIndex) {
                    this.ruleData.splice(ruleIndex, 1);
                    this.ruleCounter = this.ruleData.length;
                    this.render();
                    this.save();
                },

                addCondition: function (ruleIndex) {
                     if (!this.ruleData[ruleIndex].conditions) {
                        this.ruleData[ruleIndex].conditions = [];
                    }
                    this.ruleData[ruleIndex].conditions.push({ type: '', value: '' });
                    this.render();
                    this.save();
                },

                removeCondition: function(ruleIndex, conditionIndex) {
                    this.ruleData[ruleIndex].conditions.splice(conditionIndex, 1);
                    this.render();
                    this.save();
                },
                createConditionRow: function (ruleIndex, conditionIndex, condition) {
                    const conditionRow = document.createElement('div');
                    conditionRow.className = 'condition-row';
                    conditionRow.dataset.conditionIndex = conditionIndex;

                    const conditionOptionFormField = document.createElement('div');
                    conditionOptionFormField.className = 'form-field';

                    const conditionOptionLabel = document.createElement('label');
                    conditionOptionLabel.textContent = 'Condition ' + (conditionIndex + 1);
                    conditionOptionFormField.appendChild(conditionOptionLabel);

                    const conditionValueFormField = document.createElement('div');
                    conditionValueFormField.className = 'form-field';

                    const conditionValueLabel = document.createElement('label');
                    conditionValueLabel.textContent = 'Value';
                    conditionValueFormField.appendChild(conditionValueLabel);

                    const conditionOptions = document.createElement('select');
                    this.conditionOptionsList.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.text;
                        conditionOptions.appendChild(option);
                    });
                    conditionOptions.value = condition.type;

                    let conditionValues;
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
                    removeButton.innerHTML = `<img src="${options.plusIcon}" alt="Remove condition" title="Remove condition">`;

                    conditionOptions.addEventListener('change', (e) => {
                        this.updateCondition(ruleIndex, conditionIndex, 'type', e.target.value);
                        this.updateCondition(ruleIndex, conditionIndex, 'value', ''); // Reset value
                        const newType = e.target.value;
                        let newValueInput;
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

                updateCondition: function(ruleIndex, conditionIndex, field, value) {
                    this.ruleData[ruleIndex].conditions[conditionIndex][field] = value;
                    this.save();
                },

                setConditionValue: function (conditionValue, valueSelect) {
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

                save: function() {
                    this.hiddenInputElement.value = JSON.stringify(this.ruleData, null, 2);
                }
            };

            model.initialize(options);
            return model;
        };
    }
);
