<script setup>
// Implemented with model: zm/atria-asi/atria-dawn-preview (Atria Dawn Preview,
// running as Claude Code) — Labels module, roadmap #4.
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialLabels: {
        type: Array,
        default: () => [],
    },
    initialTasks: {
        type: Array,
        default: () => [],
    },
    csrfToken: {
        type: String,
        required: true,
    },
    routes: {
        type: Object,
        required: true,
    },
});

const labelPalette = [
    '#6b7280', '#245fba', '#1957a6', '#187443', '#a8560f',
    '#7c3aed', '#be185d', '#0f766e', '#b45309', '#374151',
];

const labels = ref(props.initialLabels.map((label) => ({ ...label })));
const tasks = ref(props.initialTasks.map((task) => ({ ...task, labels: [...(task.labels || [])] })));

const labelSearch = ref('');
const taskSearch = ref('');
const editingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');
const isSaving = ref(false);
const deletingId = ref(null);
const updatingTaskId = ref(null);

const emptyForm = () => ({
    name: '',
    color: labelPalette[0],
    description: '',
});

const form = reactive(emptyForm());

const visibleLabels = computed(() => {
    const query = labelSearch.value.trim().toLocaleLowerCase();

    if (query === '') {
        return labels.value;
    }

    return labels.value.filter((label) => (
        label.name.toLocaleLowerCase().includes(query)
        || (label.description || '').toLocaleLowerCase().includes(query)
    ));
});

const visibleTasks = computed(() => {
    const query = taskSearch.value.trim().toLocaleLowerCase();

    if (query === '') {
        return tasks.value;
    }

    return tasks.value.filter((task) => (
        task.title.toLocaleLowerCase().includes(query)
        || task.labels.some((label) => label.name.toLocaleLowerCase().includes(query))
    ));
});

function startCreate() {
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(label) {
    editingId.value = label.id;
    Object.assign(form, {
        name: label.name,
        color: label.color,
        description: label.description || '',
    });
    validationErrors.value = {};
    pageError.value = '';
}

function cancelEdit() {
    startCreate();
}

function fieldError(field) {
    return validationErrors.value[field]?.[0] || '';
}

function labelUrl(template, id) {
    return template.replace('__LABEL_ID__', String(id));
}

function taskUrl(template, taskId) {
    return template.replace('__TASK_ID__', String(taskId));
}

function taskLabelIds(task) {
    return task.labels.map((label) => label.id);
}

function labelById(id) {
    return labels.value.find((label) => label.id === id) || null;
}

async function saveLabel() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    const isEditing = editingId.value !== null;
    const url = isEditing
        ? labelUrl(props.routes.update, editingId.value)
        : props.routes.store;

    try {
        const response = await fetch(url, {
            method: isEditing ? 'PUT' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
            body: JSON.stringify({
                name: form.name,
                color: form.color || null,
                description: form.description || null,
            }),
        });

        const data = response.status === 204
            ? {}
            : await response.json().catch(() => ({}));

        if (response.status === 422) {
            validationErrors.value = data.errors || {};
            return;
        }

        if (!response.ok) {
            pageError.value = data.message || 'The label could not be saved.';
            return;
        }

        if (isEditing) {
            labels.value = labels.value.map((label) => (
                label.id === data.label.id ? data.label : label
            ));

            // Renaming or recoloring a label is reflected on every task that carries it.
            tasks.value.forEach((task) => {
                task.labels = task.labels.map((label) => (
                    label.id === data.label.id ? { ...data.label } : label
                ));
            });
        } else {
            labels.value.unshift(data.label);
        }

        startCreate();
    } catch {
        pageError.value = 'A network error prevented the label from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteLabel(label) {
    const attachedCount = tasks.value.filter(
        (task) => task.labels.some((item) => item.id === label.id),
    ).length;

    const message = attachedCount > 0
        ? `Delete “${label.name}”? It will be removed from ${attachedCount} ${attachedCount === 1 ? 'task' : 'tasks'}.`
        : `Delete “${label.name}”? This cannot be undone.`;

    if (!window.confirm(message)) {
        return;
    }

    deletingId.value = label.id;
    pageError.value = '';

    try {
        const response = await fetch(labelUrl(props.routes.destroy, label.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The label could not be deleted.';
            return;
        }

        labels.value = labels.value.filter((item) => item.id !== label.id);
        tasks.value.forEach((task) => {
            task.labels = task.labels.filter((item) => item.id !== label.id);
        });

        if (editingId.value === label.id) {
            startCreate();
        }
    } catch {
        pageError.value = `A network error prevented “${label.name}” from being deleted.`;
    } finally {
        deletingId.value = null;
    }
}

async function updateTaskLabels(task, labelIds) {
    updatingTaskId.value = task.id;
    pageError.value = '';

    try {
        const response = await fetch(taskUrl(props.routes.sync, task.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
            body: JSON.stringify({ label_ids: labelIds }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            pageError.value = data.message || `The labels on “${task.title}” could not be updated.`;
            return false;
        }

        task.labels = (data.task.labels || []).slice();
        return true;
    } catch {
        pageError.value = `A network error prevented “${task.title}” from being updated.`;
        return false;
    } finally {
        updatingTaskId.value = null;
    }
}

function toggleLabelOnTask(task, label) {
    const ids = taskLabelIds(task);
    const next = ids.includes(label.id)
        ? ids.filter((id) => id !== label.id)
        : [...ids, label.id];

    return updateTaskLabels(task, next);
}

function removeOneLabel(task, label) {
    const ids = taskLabelIds(task).filter((id) => id !== label.id);

    if (ids.length === taskLabelIds(task).length) {
        return Promise.resolve(false);
    }

    return updateTaskLabels(task, ids);
}

function labelFrequency(labelId) {
    return tasks.value.reduce(
        (sum, task) => sum + task.labels.filter((label) => label.id === labelId).length,
        0,
    );
}

function previewColor() {
    return form.color || labelPalette[0];
}
</script>

<template>
    <div class="labels-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">Project Workbench</p>
                <h1>Labels</h1>
                <p class="intro">Define reusable labels and attach them to tasks.</p>
            </div>
            <button class="button button-primary" type="button" @click="startCreate">
                New label
            </button>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>

        <section class="label-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'Create a label' : 'Edit label' }}</h2>
            <form class="label-form" @submit.prevent="saveLabel">
                <div class="form-field">
                    <label for="label-name">Name</label>
                    <input
                        id="label-name"
                        v-model="form.name"
                        maxlength="120"
                        required
                        type="text"
                        autocomplete="off"
                    >
                    <small v-if="fieldError('name')" class="field-error">{{ fieldError('name') }}</small>
                </div>

                <div class="form-field">
                    <label for="label-color">Color</label>
                    <div class="color-input">
                        <input
                            id="label-color"
                            v-model="form.color"
                            type="color"
                            :title="form.color"
                        >
                        <input
                            id="label-color-text"
                            v-model="form.color"
                            type="text"
                            maxlength="32"
                            placeholder="#6b7280"
                            autocomplete="off"
                        >
                    </div>
                    <div class="palette" role="group" aria-label="Suggested colors">
                        <button
                            v-for="color in labelPalette"
                            :key="color"
                            class="swatch"
                            :class="{ 'swatch-active': form.color.toLowerCase() === color.toLowerCase() }"
                            type="button"
                            :style="{ backgroundColor: color }"
                            :title="color"
                            @click="form.color = color"
                        ></button>
                    </div>
                    <small v-if="fieldError('color')" class="field-error">{{ fieldError('color') }}</small>
                </div>

                <div class="form-field">
                    <label for="label-description">Description</label>
                    <textarea
                        id="label-description"
                        v-model="form.description"
                        maxlength="2000"
                        rows="2"
                    ></textarea>
                    <small v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</small>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : editingId === null ? 'Create label' : 'Save changes' }}
                    </button>
                    <button
                        v-if="editingId !== null"
                        class="button button-secondary"
                        type="button"
                        @click="cancelEdit"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <section class="label-list" aria-labelledby="labels-title">
            <div class="list-heading">
                <div>
                    <h2 id="labels-title">All labels</h2>
                    <p>{{ labels.length }} {{ labels.length === 1 ? 'label' : 'labels' }}</p>
                </div>
                <label class="visually-hidden" for="label-search">Search labels</label>
                <input
                    id="label-search"
                    v-model="labelSearch"
                    type="search"
                    placeholder="Search labels"
                >
            </div>

            <p v-if="visibleLabels.length === 0" class="empty-state">
                {{ labels.length === 0 ? 'No labels yet. Create one above to get started.' : 'No labels match this search.' }}
            </p>

            <div v-else class="label-grid">
                <article v-for="label in visibleLabels" :key="label.id" class="label-card">
                    <div class="card-heading">
                        <span class="label-chip" :style="{ backgroundColor: label.color }">
                            {{ label.name }}
                        </span>
                        <span class="usage">{{ labelFrequency(label.id) }} {{ labelFrequency(label.id) === 1 ? 'task' : 'tasks' }}</span>
                    </div>
                    <p v-if="label.description" class="description">{{ label.description }}</p>
                    <p v-else class="description muted">No description</p>
                    <div class="card-actions">
                        <button class="button button-secondary" type="button" @click="startEdit(label)">
                            Edit
                        </button>
                        <button
                            class="button button-danger"
                            type="button"
                            :disabled="deletingId === label.id"
                            @click="deleteLabel(label)"
                        >
                            {{ deletingId === label.id ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <section class="task-list" aria-labelledby="tasks-title">
            <div class="list-heading">
                <div>
                    <h2 id="tasks-title">Task labels</h2>
                    <p>Toggle a label onto a task. Changes save immediately.</p>
                </div>
                <label class="visually-hidden" for="task-search">Search tasks</label>
                <input
                    id="task-search"
                    v-model="taskSearch"
                    type="search"
                    placeholder="Search tasks"
                >
            </div>

            <p v-if="visibleTasks.length === 0" class="empty-state">
                {{ tasks.length === 0 ? 'No tasks exist yet.' : 'No tasks match this search.' }}
            </p>

            <ul v-else class="task-rows">
                <li v-for="task in visibleTasks" :key="task.id" class="task-row">
                    <div class="task-row-main">
                        <h3>{{ task.title }}</h3>
                        <div class="task-labels">
                            <span v-if="task.labels.length === 0" class="muted">No labels</span>
                            <span
                                v-for="label in task.labels"
                                :key="label.id"
                                class="label-chip removable"
                                :style="{ backgroundColor: label.color }"
                            >
                                {{ label.name }}
                                <button
                                    type="button"
                                    class="chip-remove"
                                    :disabled="updatingTaskId === task.id"
                                    :aria-label="'Remove ' + label.name + ' from this task'"
                                    @click="removeOneLabel(task, label)"
                                >×</button>
                            </span>
                        </div>
                    </div>
                    <div class="task-toggle" role="group" :aria-label="'Labels for ' + task.title">
                        <button
                            v-for="label in labels"
                            :key="label.id"
                            class="toggle-chip"
                            :class="{ 'toggle-on': taskLabelIds(task).includes(label.id) }"
                            type="button"
                            :style="taskLabelIds(task).includes(label.id) ? { backgroundColor: label.color, color: '#fff' } : null"
                            :disabled="updatingTaskId === task.id"
                            @click="toggleLabelOnTask(task, label)"
                        >
                            {{ label.name }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.labels-page,
.labels-page * {
    box-sizing: border-box;
}

.labels-page {
    width: min(1120px, calc(100% - 32px));
    min-height: 100vh;
    margin: 0 auto;
    padding: 48px 0 72px;
    background: #f4f6f9;
    color: #182230;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.page-header,
.list-heading,
.card-heading,
.form-actions,
.card-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.page-header {
    align-items: flex-end;
    margin-bottom: 28px;
}

.eyebrow {
    margin: 0 0 8px;
    color: #526174;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

h1,
h2,
h3,
p {
    margin-top: 0;
}

h1 {
    margin-bottom: 8px;
    font-size: clamp(2rem, 4vw, 2.75rem);
    letter-spacing: -0.04em;
}

.intro,
.list-heading p,
.muted {
    color: #657386;
}

.intro,
.list-heading p {
    margin-bottom: 0;
}

.label-editor,
.label-list,
.task-list {
    border: 1px solid #dce2e9;
    border-radius: 16px;
    background: #fff;
    padding: 24px;
    box-shadow: 0 8px 24px rgb(26 43 65 / 5%);
}

.label-editor {
    margin-bottom: 24px;
}

.label-list {
    margin-bottom: 24px;
}

.label-editor h2,
.label-list h2,
.task-list h2 {
    margin-bottom: 18px;
    font-size: 1.2rem;
}

.label-form {
    display: grid;
    gap: 16px;
}

.form-field {
    display: grid;
    align-content: start;
    gap: 7px;
}

.form-field label {
    color: #344256;
    font-size: 0.9rem;
    font-weight: 650;
}

.form-field input,
.form-field textarea,
.form-field select,
.list-heading input {
    width: 100%;
    min-height: 42px;
    border: 1px solid #cbd4df;
    border-radius: 9px;
    background: #fff;
    color: #182230;
    font: inherit;
    padding: 9px 11px;
}

.form-field textarea {
    resize: vertical;
}

.form-field input:focus,
.form-field textarea:focus,
.list-heading input:focus {
    border-color: #3977d5;
    outline: 3px solid rgb(57 119 213 / 16%);
}

.color-input {
    display: flex;
    gap: 10px;
}

.color-input input[type="color"] {
    width: 56px;
    min-height: 42px;
    padding: 5px;
    cursor: pointer;
}

.palette {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.swatch {
    width: 26px;
    height: 26px;
    border: 2px solid transparent;
    border-radius: 999px;
    cursor: pointer;
    padding: 0;
}

.swatch:hover {
    transform: scale(1.1);
}

.swatch-active {
    border-color: #182230;
}

.field-error {
    color: #b42318;
}

.form-actions {
    justify-content: flex-start;
}

.button {
    min-height: 40px;
    border: 1px solid transparent;
    border-radius: 9px;
    cursor: pointer;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 650;
    padding: 8px 14px;
}

.button:disabled {
    cursor: wait;
    opacity: 0.65;
}

.button-primary {
    background: #245fba;
    color: #fff;
}

.button-primary:hover:not(:disabled) {
    background: #1d4f9d;
}

.button-secondary {
    border-color: #cbd4df;
    background: #fff;
    color: #344256;
}

.button-secondary:hover:not(:disabled) {
    background: #f4f6f9;
}

.button-danger {
    border-color: #f0c4c1;
    background: #fff;
    color: #a4261d;
}

.button-danger:hover:not(:disabled) {
    background: #fff4f2;
}

.notice {
    border-radius: 9px;
    margin: 0 0 18px;
    padding: 12px 14px;
}

.notice-error {
    background: #fff0ee;
    color: #a4261d;
}

.list-heading {
    margin-bottom: 20px;
}

.list-heading h2 {
    margin-bottom: 5px;
}

.list-heading input {
    min-width: 210px;
}

.label-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.label-card {
    min-width: 0;
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 18px;
}

.card-heading {
    align-items: center;
    margin-bottom: 12px;
}

.usage {
    flex: 0 0 auto;
    color: #657386;
    font-size: 0.78rem;
}

.label-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
    border-radius: 999px;
    color: #fff;
    font-size: 0.8rem;
    font-weight: 650;
    padding: 5px 10px;
    overflow-wrap: anywhere;
}

.chip-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border: 0;
    border-radius: 999px;
    background: rgb(255 255 255 / 25%);
    color: inherit;
    cursor: pointer;
    font: inherit;
    font-size: 0.75rem;
    line-height: 1;
    padding: 0;
}

.chip-remove:hover:not(:disabled) {
    background: rgb(255 255 255 / 45%);
}

.description {
    min-height: 2.4em;
    margin-bottom: 12px;
    color: #344256;
    line-height: 1.5;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.card-actions {
    justify-content: flex-start;
}

.task-rows {
    display: grid;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.task-row {
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 16px 18px;
}

.task-row-main {
    margin-bottom: 12px;
}

.task-row-main h3 {
    margin-bottom: 8px;
    font-size: 1rem;
    overflow-wrap: anywhere;
}

.task-labels {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.task-toggle {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.toggle-chip {
    border: 1px solid #cbd4df;
    border-radius: 999px;
    background: #fff;
    color: #344256;
    cursor: pointer;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 5px 11px;
}

.toggle-chip:hover:not(:disabled) {
    border-color: #9aa8b8;
    background: #f4f6f9;
}

.toggle-chip.toggle-on {
    border-color: transparent;
}

.toggle-chip:disabled {
    cursor: wait;
    opacity: 0.6;
}

.empty-state {
    margin: 0;
    border: 1px dashed #cbd4df;
    border-radius: 12px;
    color: #657386;
    padding: 28px;
    text-align: center;
}

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    clip-path: inset(50%);
}

@media (max-width: 700px) {
    .labels-page {
        width: min(100% - 24px, 560px);
        padding-top: 28px;
    }

    .page-header,
    .list-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .page-header .button {
        width: 100%;
    }

    .list-heading input {
        width: 100%;
        min-width: 0;
    }

    .label-grid {
        grid-template-columns: 1fr;
    }

    .label-editor,
    .label-list,
    .task-list {
        padding: 18px;
    }
}
</style>
