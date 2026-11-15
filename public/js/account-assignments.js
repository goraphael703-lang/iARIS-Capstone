/* Shared role-dependent assignment controls for account creation, editing and approval. */
window.IarisAccountAssignments = {
    // Reads the selected values of a <select multiple> back out.
    selectedValues(select) {
        return Array.from(select.selectedOptions).map(o => o.value);
    },
    bind(fields, choices) {
        const replaceOptions = (select, values, selected, placeholder) => {
            const multiple = select.multiple;
            select.replaceChildren(...(multiple ? [] : [new Option(placeholder, '')]));
            for (const [value, label] of Object.entries(values)) select.add(new Option(label, value));
            if (multiple) {
                const selectedSet = new Set(Array.isArray(selected) ? selected : selected ? [selected] : []);
                for (const option of select.options) option.selected = selectedSet.has(option.value);
            } else {
                select.value = Object.hasOwn(values, selected) ? selected : '';
            }
        };
        const refreshDetails = (selection = {}) => {
            const role = fields.role.value;
            const department = fields.department.value;
            const chair = role === 'program_chair' && department && department !== 'Graduate Programs';
            const assistant = role === 'assistant_principal';
            fields.programField.classList.toggle('d-none', !chair);
            fields.program.required = !!chair;
            fields.program.disabled = !chair;
            replaceOptions(fields.program, Object.fromEntries((choices.programs[department] || []).map(p => [p, p])), selection.program, 'Select program');
            fields.subLevelField.classList.toggle('d-none', !assistant);
            fields.subLevel.required = assistant;
            fields.subLevel.disabled = !assistant;
            replaceOptions(fields.subLevel, assistant ? choices.subLevels : {}, selection.subLevel ?? selection.subLevels, 'Select school level');
        };
        const refresh = (selection = {}) => {
            const departments = choices.departments[fields.role.value] || [];
            replaceOptions(fields.department, Object.fromEntries(departments.map(d => [d, d])), selection.department, 'Select department');
            if (departments.length === 1) fields.department.value = departments[0];
            fields.department.disabled = departments.length <= 1;
            refreshDetails(selection);
        };
        fields.role.addEventListener('change', () => refresh());
        fields.department.addEventListener('change', () => refreshDetails());
        refresh();
        return {refresh};
    }
};
