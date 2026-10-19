<script setup>
// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Tasks module, roadmap #2.
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialTasks: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    projects: {
        type: Array,
        default: () => [],
    },
    assignableUsers: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => ['todo', 'in_progress', 'done'],
    },
    priorities: {
        type: Array,
        default: () => ['low', 'medium', 'high', 'urgent'],
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

const priorityWeights = {
    urgent: 0,
    high: 1,
    medium: 2,
    low: 3,
};

const statusLabels = {
    todo: 'To do',
    in_progress: 'In progress',
    done: 'Done',
};

const tasks = ref(props.initialTasks.map((task) => ({ ...task })));
const editingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');
const isSaving = ref(false);
const deletingId = ref(null);

const emptyForm = () => ({
    project_id: props.projects.length > 0 ? String(props.projects[0].id) : '',
    title: '',
    description: '',
    status: 'todo',
    priority: 'medium',
    due_date: '',
    assignee_id: '',
});

const form = reactive(emptyForm());

const hasProjects = computed(() => props.projects.length > 0);

const filtersActive = computed(() => [
    props.filters.project_id,
    props.filters.status,
    props.filters.priority,
    props.filters.assignee,
    props.filters.search,
].some((value) => value !== null && value !== undefined && value !== ''));

/**
 * Mirror of the server ordering: priority weight, then due date with
 * undated tasks last, then newest first. Keeps locally added or edited
 * tasks in the same sequence the page was rendered with.
 */
function sortTasks(list) {
    return [...list].sort((a, b) => {
        const byPriority = (priorityWeights[a.priority] ?? 99) - (priorityWeights[b.priority] ?? 99);

        if (byPriority !== 0) {
            return byPriority;
        }

        if (!!a.due_date !== !!b.due_date) {
            return a.due_date ? -1 : 1;
        }

        if (a.due_date && a.due_date !== b.due_date) {
            return a.due_date < b.due_date ? -1 : 1;
        }

        return (b.created_at || '').localeCompare(a.created_at || '');
    });
}

function startCreate() {
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(task) {
    editingId.value = task.id;
    Object.assign(form, {
        project_id: String(task.project_id),
        title: task.title,
        description: task.description || '',
        status: task.status,
        priority: task.priority,
        due_date: task.due_date || '',
        assignee_id: task.assignee_id === null || task.assignee_id === undefined ? '' : String(task.assignee_id),
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

function taskUrl(template, id) {
    return template.replace('__TASK_ID__', String(id));
}

async function saveTask() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    const isEditing = editingId.value !== null;
    const url = isEditing
        ? taskUrl(props.routes.update, editingId.value)
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
                project_id: form.project_id === '' ? null : Number(form.project_id),
                title: form.title,
                description: form.description || null,
                status: form.status,
                priority: form.priority,
                due_date: form.due_date || null,
                assignee_id: form.assignee_id === '' ? null : Number(form.assignee_id),
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
            pageError.value = data.message || 'The task could not be saved.';
            return;
        }

        if (isEditing) {
            tasks.value = sortTasks(tasks.value.map((task) => (
                task.id === data.task.id ? data.task : task
            )));
        } else {
            tasks.value = sortTasks([data.task, ...tasks.value]);
        }

        startCreate();
    } catch {
        pageError.value = 'A network error prevented the task from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteTask(task) {
    if (!window.confirm('Delete “' + task.title + '”? This cannot be undone.')) {
        return;
    }

    deletingId.value = task.id;
    pageError.value = '';

    try {
        const response = await fetch(taskUrl(props.routes.destroy, task.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The task could not be deleted.';
            return;
        }

        tasks.value = tasks.value.filter((item) => item.id !== task.id);

        if (editingId.value === task.id) {
            startCreate();
        }
    } catch {
        pageError.value = 'A network error prevented the task from being deleted.';
    } finally {
        deletingId.value = null;
    }
}

function statusLabel(status) {
    return statusLabels[status] || status;
}

function priorityLabel(priority) {
    return priority.charAt(0).toUpperCase() + priority.slice(1);
}

function formatDate(value) {
    if (!value) {
        return 'No due date';
    }

    return new Date(value + 'T00:00:00').toLocaleDateString();
}

function isOverdue(task) {
    if (!task.due_date || task.status === 'done') {
        return false;
    }

    return task.due_date < new Date().toISOString().slice(0, 10);
}
</script>

<template>
    <div class="tasks-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">Project Workbench</p>
                <h1>Tasks</h1>
                <p class="intro">Plan work inside a project with a status, a priority, and an optional assignee.</p>
            </div>
            <nav class="module-links" aria-label="Modules">
                <a class="module-link" href="/projects">Projects</a>
            </nav>
            <button class="button button-primary" type="button" :disabled="!hasProjects" @click="startCreate">
                New task
            </button>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>
        <p v-if="!hasProjects" class="notice notice-info">
            Tasks belong to a project. Create a project first, then come back here.
        </p>

        <section class="filter-bar" aria-labelledby="filters-title">
            <h2 id="filters-title" class="visually-hidden">Filter tasks</h2>
            <form class="filters" method="get" :action="routes.index">
                <label class="visually-hidden" for="filter-project">Filter by project</label>
                <select id="filter-project" name="project_id" :value="filters.project_id || ''">
                    <option value="">All projects</option>
                    <option v-for="project in projects" :key="project.id" :value="String(project.id)">
                        {{ project.name }}
                    </option>
                </select>

                <label class="visually-hidden" for="filter-status">Filter by status</label>
                <select id="filter-status" name="status" :value="filters.status || ''">
                    <option value="">All statuses</option>
                    <option v-for="status in statuses" :key="status" :value="status">
                        {{ statusLabel(status) }}
                    </option>
                </select>

                <label class="visually-hidden" for="filter-priority">Filter by priority</label>
                <select id="filter-priority" name="priority" :value="filters.priority || ''">
                    <option value="">All priorities</option>
                    <option v-for="priority in priorities" :key="priority" :value="priority">
                        {{ priorityLabel(priority) }}
                    </option>
                </select>

                <label class="visually-hidden" for="filter-assignee">Filter by assignee</label>
                <select id="filter-assignee" name="assignee" :value="filters.assignee || ''">
                    <option value="">All assignees</option>
                    <option value="unassigned">Unassigned</option>
                    <option v-for="user in assignableUsers" :key="user.id" :value="String(user.id)">
                        {{ user.name }}
                    </option>
                </select>

                <label class="visually-hidden" for="filter-search">Search tasks</label>
                <input
                    id="filter-search"
                    name="search"
                    type="search"
                    placeholder="Search title or description"
                    :value="filters.search || ''"
                >

                <button class="button button-primary" type="submit">Apply filters</button>
                <a v-if="filtersActive" class="button button-secondary" :href="routes.index">Clear</a>
            </form>
        </section>

        <section class="task-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'Create a task' : 'Edit task' }}</h2>
            <form class="task-form" @submit.prevent="saveTask">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="task-project">Project</label>
                        <select id="task-project" v-model="form.project_id" required :disabled="!hasProjects">
                            <option value="" disabled>Select a project</option>
                            <option v-for="project in projects" :key="project.id" :value="String(project.id)">
                                {{ project.name }}
                            </option>
                        </select>
                        <small v-if="fieldError('project_id')" class="field-error">{{ fieldError('project_id') }}</small>
                    </div>

                    <div class="form-field">
                        <label for="task-title">Title</label>
                        <input
                            id="task-title"
                            v-model="form.title"
                            maxlength="160"
                            required
                            type="text"
                            autocomplete="off"
                        >
                        <small v-if="fieldError('title')" class="field-error">{{ fieldError('title') }}</small>
                    </div>
                </div>

                <div class="form-field">
                    <label for="task-description">Description</label>
                    <textarea
                        id="task-description"
                        v-model="form.description"
                        maxlength="2000"
                        rows="3"
                    ></textarea>
                    <small v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</small>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="task-status">Status</label>
                        <select id="task-status" v-model="form.status" required>
                            <option v-for="status in statuses" :key="status" :value="status">
                                {{ statusLabel(status) }}
                            </option>
                        </select>
                        <small v-if="fieldError('status')" class="field-error">{{ fieldError('status') }}</small>
                    </div>

                    <div class="form-field">
                        <label for="task-priority">Priority</label>
                        <select id="task-priority" v-model="form.priority" required>
                            <option v-for="priority in priorities" :key="priority" :value="priority">
                                {{ priorityLabel(priority) }}
                            </option>
                        </select>
                        <small v-if="fieldError('priority')" class="field-error">{{ fieldError('priority') }}</small>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="task-due-date">Due date</label>
                        <input id="task-due-date" v-model="form.due_date" type="date">
                        <small v-if="fieldError('due_date')" class="field-error">{{ fieldError('due_date') }}</small>
                    </div>

                    <div class="form-field">
                        <label for="task-assignee">Assignee</label>
                        <select id="task-assignee" v-model="form.assignee_id">
                            <option value="">Unassigned</option>
                            <option v-for="user in assignableUsers" :key="user.id" :value="String(user.id)">
                                {{ user.name }}
                            </option>
                        </select>
                        <small v-if="fieldError('assignee_id')" class="field-error">{{ fieldError('assignee_id') }}</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button
                        class="button button-primary"
                        type="submit"
                        :disabled="isSaving || !hasProjects"
                    >
                        {{ isSaving ? 'Saving…' : editingId === null ? 'Create task' : 'Save changes' }}
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

        <section class="task-list" aria-labelledby="list-title">
            <div class="list-heading">
                <div>
                    <h2 id="list-title">{{ filtersActive ? 'Filtered tasks' : 'All tasks' }}</h2>
                    <p>{{ tasks.length }} {{ tasks.length === 1 ? 'task' : 'tasks' }}</p>
                </div>
            </div>

            <p v-if="tasks.length === 0" class="empty-state">
                {{ hasProjects ? 'No tasks yet. Create one above to get started.' : 'No projects available yet.' }}
            </p>

            <table v-else class="task-table">
                <thead>
                    <tr>
                        <th scope="col">Task</th>
                        <th scope="col">Project</th>
                        <th scope="col">Status</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Due date</th>
                        <th scope="col">Assignee</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="task in tasks" :key="task.id">
                        <td class="cell-title">
                            <span class="task-title">{{ task.title }}</span>
                            <span v-if="task.description" class="task-description">{{ task.description }}</span>
                        </td>
                        <td>{{ task.project_name || '—' }}</td>
                        <td>
                            <span class="badge" :class="'status-' + task.status">{{ statusLabel(task.status) }}</span>
                        </td>
                        <td>
                            <span class="badge" :class="'priority-' + task.priority">{{ priorityLabel(task.priority) }}</span>
                        </td>
                        <td :class="{ overdue: isOverdue(task) }">
                            {{ formatDate(task.due_date) }}<template v-if="isOverdue(task)"> · overdue</template>
                        </td>
                        <td>{{ task.assignee_name || 'Unassigned' }}</td>
                        <td class="cell-actions">
                            <button class="button button-secondary" type="button" @click="startEdit(task)">
                                Edit
                            </button>
                            <button
                                class="button button-danger"
                                type="button"
                                :disabled="deletingId === task.id"
                                @click="deleteTask(task)"
                            >
                                {{ deletingId === task.id ? 'Deleting…' : 'Delete' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

<style scoped>
.tasks-page,
.tasks-page * {
    box-sizing: border-box;
}

.tasks-page {
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
.cell-actions,
.filters {
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

.filter-bar,
.task-editor,
.task-list {
    border: 1px solid #dce2e9;
    border-radius: 16px;
    background: #fff;
    padding: 24px;
    box-shadow: 0 8px 24px rgb(26 43 65 / 5%);
}

.filter-bar {
    margin-bottom: 24px;
    padding: 18px 24px;
}

.filters {
    justify-content: flex-start;
    flex-wrap: wrap;
}

.task-editor {
    margin-bottom: 24px;
}

.task-editor h2,
.task-list h2 {
    margin-bottom: 18px;
    font-size: 1.2rem;
}

.task-form {
    display: grid;
    gap: 16px;
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

.form-field input,
.form-field textarea,
.form-field select,
.filters input,
.filters select {
    width: 100%;
    min-height: 42px;
    border: 1px solid #cbd4df;
    border-radius: 9px;
    background: #fff;
    color: #182230;
    font: inherit;
    padding: 9px 11px;
}

.filters select {
    min-width: 150px;
    width: auto;
}

.filters input {
    min-width: 200px;
    width: auto;
}

.form-field textarea {
    resize: vertical;
}

.form-field input:focus,
.form-field textarea:focus,
.form-field select:focus,
.filters input:focus,
.filters select:focus {
    border-color: #3977d5;
    outline: 3px solid rgb(57 119 213 / 16%);
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
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 650;
    padding: 8px 14px;
    text-decoration: none;
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

.notice-info {
    background: #e8f2ff;
    color: #1957a6;
}

.list-heading {
    margin-bottom: 20px;
}

.list-heading h2 {
    margin-bottom: 5px;
}

.task-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
}

.task-table th,
.task-table td {
    border-bottom: 1px solid #e1e6ed;
    padding: 12px 10px;
    text-align: left;
    vertical-align: top;
}

.task-table th {
    color: #526174;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.task-table tbody tr:last-child td {
    border-bottom: none;
}

.cell-title {
    min-width: 220px;
}

.task-title {
    display: block;
    font-weight: 650;
    overflow-wrap: anywhere;
}

.task-description {
    display: block;
    margin-top: 4px;
    color: #657386;
    font-size: 0.85rem;
    overflow-wrap: anywhere;
}

.cell-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
}

.badge {
    display: inline-block;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 5px 9px;
    white-space: nowrap;
}

.status-todo {
    background: #eef2f7;
    color: #526174;
}

.status-in_progress {
    background: #e8f2ff;
    color: #1957a6;
}

.status-done {
    background: #e9f7ef;
    color: #187443;
}

.priority-low {
    background: #f1f4f8;
    color: #657386;
}

.priority-medium {
    background: #eef2f7;
    color: #344256;
}

.priority-high {
    background: #fff4e5;
    color: #a05a00;
}

.priority-urgent {
    background: #fff0ee;
    color: #a4261d;
}

.overdue {
    color: #a4261d;
    font-weight: 650;
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

@media (max-width: 900px) {
    .task-table thead {
        display: none;
    }

    .task-table,
    .task-table tbody,
    .task-table tr,
    .task-table td {
        display: block;
        width: 100%;
    }

    .task-table tr {
        border: 1px solid #e1e6ed;
        border-radius: 12px;
        margin-bottom: 12px;
        padding: 6px 12px;
    }

    .task-table td {
        border-bottom: none;
        padding: 6px 0;
    }

    .cell-actions {
        justify-content: flex-start;
    }
}

@media (max-width: 700px) {
    .tasks-page {
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

    .filters {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }

    .filters input,
    .filters select {
        min-width: 0;
        width: 100%;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .filter-bar,
    .task-editor,
    .task-list {
        padding: 18px;
    }
}
</style>
