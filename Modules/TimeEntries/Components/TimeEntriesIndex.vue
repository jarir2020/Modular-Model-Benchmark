<script setup>
// Mounted by resources/js/app.js, which matches the `time-entries` slug from the
// folder name to the #time-entries-app root and #time-entries-page-props script.

import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialEntries: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    totals: {
        type: Object,
        default: () => ({ total_minutes: 0, billable_minutes: 0, count: 0 }),
    },
    tasks: {
        type: Array,
        default: () => [],
    },
    users: {
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

const entries = ref(props.initialEntries.map((entry) => ({ ...entry })));
const totals = ref({ ...props.totals });

const taskFilter = ref(props.filters?.task_id ?? '');
const userFilter = ref(props.filters?.user_id ?? '');
const billableFilter = ref(props.filters?.billable ?? '');

const isCreating = ref(false);
const editingId = ref(null);
const isSaving = ref(false);
const deletingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');

const emptyForm = () => ({
    task_id: taskFilter.value || (props.tasks[0]?.id ?? ''),
    user_id: props.users[0]?.id ?? '',
    duration_minutes: '',
    spent_on: new Date().toISOString().slice(0, 10),
    note: '',
    billable: true,
});

const form = reactive(emptyForm());

function durationLabel(minutes) {
    const value = Number(minutes);

    if (!Number.isFinite(value) || value <= 0) {
        return '';
    }

    const hours = Math.floor(value / 60);
    const rest = value % 60;

    if (hours === 0) {
        return rest + 'm';
    }

    if (rest === 0) {
        return hours + 'h';
    }

    return hours + 'h ' + rest + 'm';
}

function hoursTotal(minutes) {
    return (Number(minutes) / 60).toFixed(1) + 'h';
}

function entryUrl(id) {
    return props.routes.update
        .replace('__ENTRY_ID__', String(id));
}

function destroyUrl(id) {
    return props.routes.destroy.replace('__ENTRY_ID__', String(id));
}

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(entry) {
    isCreating.value = true;
    editingId.value = entry.id;
    Object.assign(form, {
        task_id: String(entry.task_id),
        user_id: String(entry.user_id),
        duration_minutes: String(entry.duration_minutes),
        spent_on: entry.spent_on || '',
        note: entry.note || '',
        billable: Boolean(entry.billable),
    });
    validationErrors.value = {};
    pageError.value = '';
}

function cancelEdit() {
    isCreating.value = false;
    editingId.value = null;
    validationErrors.value = {};
    pageError.value = '';
}

function fieldError(field) {
    return validationErrors.value[field]?.[0] || '';
}

function applyFilters() {
    const url = new URL(window.location.href);

    for (const [key, value] of [
        ['task_id', taskFilter.value],
        ['user_id', userFilter.value],
        ['billable', billableFilter.value],
    ]) {
        if (value === '' || value === null) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
    }

    window.location.assign(url.toString());
}

async function saveEntry() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    try {
        const isUpdate = editingId.value !== null;
        const response = await fetch(isUpdate ? entryUrl(editingId.value) : props.routes.store, {
            method: isUpdate ? 'PUT' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
            body: JSON.stringify({
                task_id: form.task_id || null,
                user_id: form.user_id || null,
                duration_minutes: form.duration_minutes === '' ? null : Number(form.duration_minutes),
                spent_on: form.spent_on || null,
                note: form.note === '' ? null : form.note,
                billable: Boolean(form.billable),
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (response.status === 422) {
            validationErrors.value = data.errors || {};
            return;
        }

        if (!response.ok) {
            pageError.value = data.message || 'The time entry could not be saved.';
            return;
        }

        if (isUpdate) {
            entries.value = entries.value.map((entry) =>
                entry.id === data.entry.id ? data.entry : entry,
            );
        } else {
            entries.value.unshift(data.entry);
        }

        recalcTotals();
        cancelEdit();
    } catch {
        pageError.value = 'A network error prevented the time entry from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteEntry(entry) {
    if (!window.confirm('Delete this time entry? Logged time cannot be recovered.')) {
        return;
    }

    deletingId.value = entry.id;
    pageError.value = '';

    try {
        const response = await fetch(destroyUrl(entry.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The time entry could not be deleted.';
            return;
        }

        entries.value = entries.value.filter((item) => item.id !== entry.id);
        recalcTotals();
    } catch {
        pageError.value = 'A network error prevented the time entry from being deleted.';
    } finally {
        deletingId.value = null;
    }
}

/**
 * Keep the totals row in step with list mutations without a reload; the
 * server recomputes them for the active filter set on the next page load.
 */
function recalcTotals() {
    const billableMinutes = entries.value
        .filter((entry) => entry.billable)
        .reduce((sum, entry) => sum + Number(entry.duration_minutes || 0), 0);
    const totalMinutes = entries.value
        .reduce((sum, entry) => sum + Number(entry.duration_minutes || 0), 0);

    totals.value = {
        total_minutes: totalMinutes,
        billable_minutes: billableMinutes,
        count: entries.value.length,
    };
}

function formatDate(value) {
    if (!value) {
        return '';
    }
    return new Date(value).toLocaleDateString();
}
</script>

<template>
    <div class="time-entries-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Time Entries</h1>
                    <p class="intro">Log the time spent on each task, mark it billable, and watch the totals add up.</p>
                </div>
                <div class="page-header--actions">
                    <nav class="module-links" aria-label="Modules">
                        <a class="module-link" href="/projects">Projects</a>
                        <a class="module-link" href="/tasks">Tasks</a>
                    </nav>
                    <button
                        v-if="!isCreating"
                        class="button button-primary"
                        type="button"
                        :disabled="tasks.length === 0 || users.length === 0"
                        @click="startCreate"
                    >
                        Log time
                    </button>
                </div>
            </div>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>
        <p v-if="tasks.length === 0" class="notice notice-info">
            Time is logged against tasks. Create a task first.
        </p>

        <section class="totals-row" aria-label="Totals">
            <div class="total-card">
                <span class="total-label">Entries</span>
                <span class="total-value">{{ totals.count }}</span>
            </div>
            <div class="total-card">
                <span class="total-label">Total logged</span>
                <span class="total-value">{{ hoursTotal(totals.total_minutes) }}</span>
            </div>
            <div class="total-card">
                <span class="total-label">Billable</span>
                <span class="total-value">{{ hoursTotal(totals.billable_minutes) }}</span>
            </div>
            <div class="total-card">
                <span class="total-label">Non-billable</span>
                <span class="total-value">{{ hoursTotal(totals.total_minutes - totals.billable_minutes) }}</span>
            </div>
        </section>

        <section v-if="isCreating" class="entry-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'Log time' : 'Edit time entry' }}</h2>
            <form class="entry-form" @submit.prevent="saveEntry">
                <div class="form-row">
                    <div class="form-field">
                        <label for="entry-task">Task</label>
                        <select id="entry-task" v-model="form.task_id" required>
                            <option value="" disabled>Select a task</option>
                            <option v-for="task in tasks" :key="task.id" :value="task.id">
                                {{ task.title }} — {{ task.project_name || 'No project' }}
                            </option>
                        </select>
                        <small v-if="fieldError('task_id')" class="field-error">{{ fieldError('task_id') }}</small>
                    </div>

                    <div class="form-field">
                        <label for="entry-user">User</label>
                        <select id="entry-user" v-model="form.user_id" required>
                            <option value="" disabled>Select a user</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">
                                {{ user.name }}
                            </option>
                        </select>
                        <small v-if="fieldError('user_id')" class="field-error">{{ fieldError('user_id') }}</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="entry-duration">Duration (minutes)</label>
                        <input
                            id="entry-duration"
                            v-model="form.duration_minutes"
                            type="number"
                            min="1"
                            max="1440"
                            step="1"
                            required
                        >
                        <small v-if="fieldError('duration_minutes')" class="field-error">{{ fieldError('duration_minutes') }}</small>
                    </div>

                    <div class="form-field">
                        <label for="entry-date">Date</label>
                        <input id="entry-date" v-model="form.spent_on" type="date" required>
                        <small v-if="fieldError('spent_on')" class="field-error">{{ fieldError('spent_on') }}</small>
                    </div>

                    <div class="form-field form-field--checkbox">
                        <label for="entry-billable">
                            <input id="entry-billable" v-model="form.billable" type="checkbox">
                            Billable
                        </label>
                    </div>
                </div>

                <div class="form-field">
                    <label for="entry-note">Note</label>
                    <textarea id="entry-note" v-model="form.note" rows="2" placeholder="What was this time spent on? (optional)"></textarea>
                    <small v-if="fieldError('note')" class="field-error">{{ fieldError('note') }}</small>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : (editingId === null ? 'Log entry' : 'Save changes') }}
                    </button>
                    <button class="button button-secondary" type="button" @click="cancelEdit">
                        Done
                    </button>
                </div>
            </form>
        </section>

        <section class="entry-list" aria-labelledby="entries-list-title">
            <div class="list-heading">
                <div>
                    <h2 id="entries-list-title">All time entries</h2>
                    <p>{{ entries.length }} entr{{ entries.length === 1 ? 'y' : 'ies' }}</p>
                </div>
                <div class="filters">
                    <label class="visually-hidden" for="entry-task-filter">Filter by task</label>
                    <select id="entry-task-filter" v-model="taskFilter" @change="applyFilters">
                        <option value="">All tasks</option>
                        <option v-for="task in tasks" :key="task.id" :value="task.id">
                            {{ task.title }}
                        </option>
                    </select>
                    <label class="visually-hidden" for="entry-user-filter">Filter by user</label>
                    <select id="entry-user-filter" v-model="userFilter" @change="applyFilters">
                        <option value="">All users</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">
                            {{ user.name }}
                        </option>
                    </select>
                    <label class="visually-hidden" for="entry-billable-filter">Filter by billable</label>
                    <select id="entry-billable-filter" v-model="billableFilter" @change="applyFilters">
                        <option value="">Billable and not</option>
                        <option value="1">Billable only</option>
                        <option value="0">Non-billable only</option>
                    </select>
                </div>
            </div>

            <p v-if="entries.length === 0" class="empty-state">
                No time entries match these filters yet. Log time above to start tracking.
            </p>

            <ul v-else class="entry-rows">
                <li v-for="entry in entries" :key="entry.id" class="entry-row">
                    <div class="entry-main">
                        <span class="task-pill">{{ entry.task_title ?? 'Deleted task' }}</span>
                        <span class="entry-duration">{{ durationLabel(entry.duration_minutes) }}</span>
                        <span class="entry-date">{{ formatDate(entry.spent_on) }}</span>
                        <span class="entry-user">{{ entry.user_name || 'Unknown user' }}</span>
                        <span class="entry-badge" :class="entry.billable ? 'entry-badge--billable' : 'entry-badge--nonbillable'">
                            {{ entry.billable ? 'Billable' : 'Non-billable' }}
                        </span>
                    </div>
                    <p v-if="entry.note" class="entry-note">{{ entry.note }}</p>
                    <div class="entry-actions">
                        <button
                            class="button button-secondary"
                            type="button"
                            @click="startEdit(entry)"
                        >
                            Edit
                        </button>
                        <button
                            class="button button-danger"
                            type="button"
                            :disabled="deletingId === entry.id"
                            @click="deleteEntry(entry)"
                        >
                            {{ deletingId === entry.id ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.time-entries-page,
.time-entries-page * {
    box-sizing: border-box;
}

.time-entries-page {
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
.form-actions,
.page-header--actions,
.entry-row,
.entry-main,
.entry-actions,
.filters,
.form-row,
.totals-row {
    display: flex;
    align-items: center;
    gap: 16px;
}

.page-header {
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 24px;
}

.page-header--wrapper {
    display: flex;
    flex: 1 1 auto;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
}

.page-header--actions {
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
}

.module-links {
    display: flex;
    gap: 10px;
}

.module-link {
    color: #526174;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
}

.module-link:hover {
    color: #182230;
    text-decoration: underline;
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
    margin: 0;
    font-size: 1.8rem;
}

.intro {
    margin: 6px 0 0;
    color: #526174;
    font-size: 0.95rem;
}

.totals-row {
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}

.total-card {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 140px;
    padding: 14px 18px;
    background: #ffffff;
    border: 1px solid #dde3ea;
    border-radius: 10px;
}

.total-label {
    color: #526174;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.total-value {
    font-size: 1.3rem;
    font-weight: 700;
}

.entry-editor {
    margin-bottom: 24px;
    padding: 20px;
    background: #ffffff;
    border: 1px solid #dde3ea;
    border-radius: 10px;
}

.entry-editor h2 {
    margin: 0 0 14px;
    font-size: 1.1rem;
}

.form-row {
    flex-wrap: wrap;
    align-items: flex-end;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
    min-width: 180px;
}

.form-field--checkbox {
    justify-content: flex-end;
}

.form-field label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #38414e;
}

.form-field--checkbox label {
    display: flex;
    align-items: center;
    gap: 8px;
}

input[type="date"],
input[type="number"],
select,
textarea,
input[type="search"] {
    padding: 8px 10px;
    border: 1px solid #c8d0da;
    border-radius: 8px;
    font: inherit;
    background: #ffffff;
    color: inherit;
}

textarea {
    resize: vertical;
}

.form-hint {
    margin: 4px 0 12px;
    color: #526174;
    font-size: 0.82rem;
}

.form-actions {
    gap: 10px;
}

.button {
    padding: 8px 16px;
    border: 1px solid transparent;
    border-radius: 8px;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.button-primary {
    background: #2f5fe0;
    color: #ffffff;
}

.button-secondary {
    background: #ffffff;
    border-color: #c8d0da;
    color: #182230;
}

.button-danger {
    background: #ffffff;
    border-color: #e3b7b7;
    color: #a32727;
}

.button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.notice {
    padding: 10px 14px;
    margin: 0 0 16px;
    border-radius: 8px;
    font-size: 0.9rem;
}

.notice-error {
    background: #fdeaea;
    color: #8f1f1f;
    border: 1px solid #eec4c4;
}

.notice-info {
    background: #eef3fb;
    color: #2d4a7e;
    border: 1px solid #ccdcee;
}

.list-heading {
    justify-content: space-between;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.list-heading h2 {
    margin: 0;
    font-size: 1.15rem;
}

.list-heading p {
    margin: 4px 0 0;
    color: #526174;
    font-size: 0.88rem;
}

.filters {
    flex-wrap: wrap;
}

.empty-state {
    padding: 32px;
    background: #ffffff;
    border: 1px dashed #c8d0da;
    border-radius: 10px;
    color: #526174;
    text-align: center;
}

.entry-rows {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.entry-row {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
    padding: 14px 16px;
    background: #ffffff;
    border: 1px solid #dde3ea;
    border-radius: 10px;
}

.entry-main {
    flex-wrap: wrap;
    gap: 10px;
}

.task-pill {
    padding: 4px 10px;
    background: #eef3fb;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #2d4a7e;
}

.entry-duration {
    font-weight: 700;
}

.entry-date,
.entry-user {
    color: #526174;
    font-size: 0.88rem;
}

.entry-badge {
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
}

.entry-badge--billable {
    background: #e6f5ea;
    color: #1f6b38;
}

.entry-badge--nonbillable {
    background: #f1f3f6;
    color: #526174;
}

.entry-note {
    margin: 0;
    color: #38414e;
    font-size: 0.9rem;
}

.entry-actions {
    justify-content: flex-end;
    gap: 8px;
}

.field-error {
    color: #a32727;
    font-size: 0.78rem;
}

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
