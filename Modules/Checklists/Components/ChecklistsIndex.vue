<script setup>
// Implemented with model: claude-sonnet-5-5 (Claude Sonnet 5.5) — Checklists module, roadmap #7.

import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialChecklists: {
        type: Array,
        default: () => [],
    },
    taskId: {
        type: [Number, null],
        default: null,
    },
    taskTitle: {
        type: String,
        default: '',
    },
    csrfToken: {
        type: String,
        required: true,
    },
    projects: {
        type: Array,
        default: () => [],
    },
    tasks: {
        type: Array,
        default: () => [],
    },
    routes: {
        type: Object,
        required: true,
    },
});

const checklists = ref(props.initialChecklists.map((c) => ({ ...c })));

const editingChecklistId = ref(null);
const validationErrors = ref({});
const pageError = ref('');
const isSaving = ref(false);
const deletingId = ref(null);

const selectedTaskId = ref(props.taskId !== null ? String(props.taskId) : '');

const emptyForm = () => ({
    task_id: props.taskId !== null ? String(props.taskId) : '',
    title: '',
    position: null,
});

const form = reactive(emptyForm());

const hasTask = computed(() => selectedTaskId.value !== '');

const startCreate = () => {
    editingChecklistId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
};

const startEdit = (checklist) => {
    editingChecklistId.value = checklist.id;
    Object.assign(form, {
        task_id: String(checklist.task_id),
        title: checklist.title,
        position: checklist.position,
    });
    validationErrors.value = {};
    pageError.value = '';
};

const cancelEdit = () => startCreate();

function fieldError(field) {
    return validationErrors.value[field]?.[0] || '';
}

function checklistUrl(template, id) {
    return template.replace('__CHECKLIST_ID__', String(id));
}

async function saveChecklist() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    const isEditing = editingChecklistId.value !== null;
    const url = isEditing
        ? checklistUrl(props.routes.update, editingChecklistId.value)
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
                task_id: form.task_id === '' ? null : Number(form.task_id),
                title: form.title,
                position: form.position === null ? null : Number(form.position),
            }),
        });

        const data = response.status === 204 ? {} : await response.json().catch(() => ({}));

        if (response.status === 422) {
            validationErrors.value = data.errors || {};
            pageError.value = data.message || '';
            return;
        }

        if (!response.ok) {
            pageError.value = data.message || 'The checklist could not be saved.';
            return;
        }

        if (isEditing) {
            checklists.value = checklists.value.map((c) => (c.id === data.checklist.id ? data.checklist : c));
        } else {
            checklists.value = [...checklists.value, data.checklist].sort((a, b) => a.position - b.position);
        }

        startCreate();
    } catch {
        pageError.value = 'A network error prevented the checklist from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteChecklist(checklist) {
    if (!window.confirm('Delete “' + checklist.title + '”? This cannot be undone.')) {
        return;
    }

    deletingId.value = checklist.id;
    pageError.value = '';

    try {
        const response = await fetch(checklistUrl(props.routes.destroy, checklist.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The checklist could not be deleted.';
            return;
        }

        checklists.value = checklists.value.filter((c) => c.id !== checklist.id);

        if (editingChecklistId.value === checklist.id) {
            startCreate();
        }
    } catch {
        pageError.value = 'A network error prevented the checklist from being deleted.';
    } finally {
        deletingId.value = null;
    }
}

function moveChecklist(checklistId, direction) {
    const index = checklists.value.findIndex((c) => c.id === checklistId);
    if (index === -1) return;

    const nextIndex = index + direction;
    if (nextIndex < 0 || nextIndex >= checklists.value.length) return;

    const copy = [...checklists.value];
    const [item] = copy.splice(index, 1);
    copy.splice(nextIndex, 0, item);

    // Update local positions for immediate UI feedback.
    const updated = copy.map((c, i) => ({ ...c, position: i }));
    checklists.value = updated;

    // Persist ordering (server expects IDs in new order).
    persistOrder().catch(() => undefined);
}

async function persistOrder() {
    if (!hasTask.value) return;

    const response = await fetch(props.routes.reorder, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': props.csrfToken,
        },
        body: JSON.stringify({
            task_id: Number(selectedTaskId.value),
            checklist_ids: checklists.value.map((c) => c.id),
        }),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        pageError.value = data.message || 'Failed to save checklist order.';
    }
}
</script>

<template>
    <div class="checklists-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">Project Workbench</p>
                <h1>Checklists</h1>
                <p class="intro">Add and reorder named checklists on a task.</p>
            </div>
            <nav class="module-links" aria-label="Modules">
                <a class="module-link" href="/projects">Projects</a>
                <a class="module-link" href="/tasks">Tasks</a>
            </nav>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>

        <section class="task-picker" aria-labelledby="task-picker-title">
            <h2 id="task-picker-title" class="visually-hidden">Pick task</h2>

            <div class="form-grid">
                <div class="form-field">
                    <label for="task-select">Task</label>
                    <select
                        id="task-select"
                        v-model="selectedTaskId"
                        @change="() => { window.location.href = '/checklists?task_id=' + selectedTaskId; }"
                    >
                        <option value="" disabled>Select a task</option>
                        <option v-for="t in tasks" :key="t.id" :value="String(t.id)">
                            {{ t.title }}
                        </option>
                    </select>
                </div>
            </div>
        </section>

        <section class="checklist-editor" aria-labelledby="editor-title" v-if="hasTask">
            <h2 id="editor-title">{{ editingChecklistId === null ? 'Create a checklist' : 'Edit checklist' }}</h2>
            <form class="checklist-form" @submit.prevent="saveChecklist">
                <div class="form-field">
                    <label for="checklist-title">Title</label>
                    <input id="checklist-title" v-model="form.title" maxlength="160" required type="text" autocomplete="off">
                    <small v-if="fieldError('title')" class="field-error">{{ fieldError('title') }}</small>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : editingChecklistId === null ? 'Create checklist' : 'Save changes' }}
                    </button>
                    <button v-if="editingChecklistId !== null" class="button button-secondary" type="button" @click="cancelEdit">
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <section v-else class="empty-state" aria-live="polite">
            Select a task to manage its checklists.
        </section>

        <section class="checklist-list" aria-labelledby="list-title" v-if="hasTask">
            <div class="list-heading">
                <div>
                    <h2 id="list-title">All checklists</h2>
                    <p>{{ checklists.length }} {{ checklists.length === 1 ? 'checklist' : 'checklists' }}</p>
                </div>
            </div>

            <p v-if="checklists.length === 0" class="empty-state">
                No checklists yet. Create one above to get started.
            </p>

            <div v-else class="checklist-grid">
                <article v-for="checklist in checklists" :key="checklist.id" class="checklist-card">
                    <div class="card-heading">
                        <h3>{{ checklist.title }}</h3>
                        <div class="card-actions">
                            <button
                                class="button button-secondary"
                                type="button"
                                :disabled="deletingId === checklist.id"
                                @click="startEdit(checklist)"
                            >
                                Edit
                            </button>
                            <button
                                class="button button-danger"
                                type="button"
                                :disabled="deletingId === checklist.id"
                                @click="deleteChecklist(checklist)"
                            >
                                {{ deletingId === checklist.id ? 'Deleting…' : 'Delete' }}
                            </button>
                        </div>
                    </div>

                    <div class="order-controls" role="group" aria-label="Reorder checklist">
                        <button
                            class="button button-secondary"
                            type="button"
                            :disabled="checklists[0]?.id === checklist.id"
                            @click="moveChecklist(checklist.id, -1)"
                        >
                            ↑
                        </button>
                        <button
                            class="button button-secondary"
                            type="button"
                            :disabled="checklists[checklists.length - 1]?.id === checklist.id"
                            @click="moveChecklist(checklist.id, 1)"
                        >
                            ↓
                        </button>
                    </div>

                    <p class="muted">Items UI will appear in the Checklist Items module.</p>
                </article>
            </div>
        </section>
    </div>
</template>

<style scoped>
.checklists-page,
.checklists-page * {
    box-sizing: border-box;
}

.checklists-page {
    width: min(1120px, calc(100% - 32px));
    min-height: 100vh;
    margin: 0 auto;
    padding: 48px 0 72px;
    background: #f4f6f9;
    color: #182230;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
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

h1 {
    margin: 0 0 8px;
    font-size: clamp(2rem, 4vw, 2.75rem);
    letter-spacing: -0.04em;
}

.intro {
    margin: 0;
    color: #657386;
}

.module-links {
    display: flex;
    gap: 10px;
}

.module-link {
    color: #245fba;
    font-size: 0.9rem;
    font-weight: 650;
    text-decoration: none;
}

.module-link:hover {
    text-decoration: underline;
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

.task-picker,
.checklist-editor,
.checklist-list {
    border: 1px solid #dce2e9;
    border-radius: 16px;
    background: #fff;
    padding: 24px;
    box-shadow: 0 8px 24px rgb(26 43 65 / 5%);
    margin-bottom: 24px;
}

.task-picker {
    margin-top: 8px;
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

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
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

.form-field select,
.form-field input {
    width: 100%;
    min-height: 42px;
    border: 1px solid #cbd4df;
    border-radius: 9px;
    background: #fff;
    color: #182230;
    font: inherit;
    padding: 9px 11px;
}

.field-error {
    color: #b42318;
}

.form-actions {
    display: flex;
    gap: 12px;
}

.button {
    min-height: 40px;
    border: 1px solid transparent;
    border-radius: 9px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
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

.list-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.checklist-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.checklist-card {
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 18px;
    background: #fff;
}

.card-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.card-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.order-controls {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}

.muted {
    color: #657386;
}

.empty-state {
    margin: 0;
    border: 1px dashed #cbd4df;
    border-radius: 12px;
    color: #657386;
    padding: 28px;
    text-align: center;
}

@media (max-width: 700px) {
    .checklists-page {
        width: min(100% - 24px, 560px);
        padding-top: 28px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .form-grid,
    .checklist-grid {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .button {
        width: 100%;
    }

    .card-actions {
        flex-direction: column;
    }
}
</style>
