<script setup>
// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Task Dependencies module, roadmap #9.
// Mounted by resources/js/app.js, which matches the `task-dependencies` slug from the
// folder name to the #task-dependencies-app root and #task-dependencies-page-props script.

import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialDependencies: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    projects: {
        type: Array,
        default: () => [],
    },
    tasks: {
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

const dependencies = ref(props.initialDependencies.map((dependency) => ({ ...dependency })));

// The page is scoped server-side through ?project_id=, so the picker keeps the
// URL in step with what it shows; a full reload re-reads the filter from props.
const projectFilter = ref(props.filters?.project_id ?? '');
const search = ref('');
const isCreating = ref(false);
const validationErrors = ref({});
const pageError = ref('');
const isSaving = ref(false);
const deletingId = ref(null);

const emptyForm = () => ({
    project_id: projectFilter.value || (props.projects[0]?.id ?? ''),
    blocking_task_id: '',
    blocked_task_id: '',
});

const form = reactive(emptyForm());

/**
 * A dependency lives inside one project (the controller rejects cross-project
 * links), so both pickers list only that project's tasks.
 */
const formTasks = computed(() =>
    props.tasks.filter((task) => String(task.project_id) === String(form.project_id)),
);

const visibleDependencies = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (query === '') {
        return dependencies.value;
    }

    return dependencies.value.filter((dependency) =>
        (dependency.blocking_task_title || '').toLowerCase().includes(query)
        || (dependency.blocked_task_title || '').toLowerCase().includes(query)
        || (dependency.blocking_task_project_name || '').toLowerCase().includes(query),
    );
});

function startCreate() {
    isCreating.value = true;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function cancelCreate() {
    isCreating.value = false;
    validationErrors.value = {};
    pageError.value = '';
}

function fieldError(field) {
    return validationErrors.value[field]?.[0] || '';
}

function dependencyUrl(id) {
    return props.routes.destroy.replace('__DEPENDENCY_ID__', String(id));
}

/**
 * Changing the project scope resets both ends: a link that was valid for one
 * project's tasks cannot survive a move to another's.
 */
function onProjectChange() {
    form.blocking_task_id = '';
    form.blocked_task_id = '';
}

function applyProjectFilter() {
    const url = new URL(window.location.href);

    if (projectFilter.value === '' || projectFilter.value === null) {
        url.searchParams.delete('project_id');
    } else {
        url.searchParams.set('project_id', String(projectFilter.value));
    }

    window.location.assign(url.toString());
}

async function saveDependency() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    try {
        const response = await fetch(props.routes.store, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
            body: JSON.stringify({
                blocking_task_id: form.blocking_task_id || null,
                blocked_task_id: form.blocked_task_id || null,
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (response.status === 422) {
            validationErrors.value = data.errors || {};
            return;
        }

        if (!response.ok) {
            pageError.value = data.message || 'The dependency could not be recorded.';
            return;
        }

        dependencies.value.unshift(data.dependency);
        // Keep the form open so a chain (A → B → C) can be recorded in one
        // sitting, but clear the direction the user just committed.
        Object.assign(form, emptyForm());
    } catch {
        pageError.value = 'A network error prevented the dependency from being recorded.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteDependency(dependency) {
    const label = dependency.blocking_task_title + ' → ' + dependency.blocked_task_title;

    if (!window.confirm('Remove this dependency? ' + label)) {
        return;
    }

    deletingId.value = dependency.id;
    pageError.value = '';

    try {
        const response = await fetch(dependencyUrl(dependency.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The dependency could not be removed.';
            return;
        }

        dependencies.value = dependencies.value.filter((item) => item.id !== dependency.id);
    } catch {
        pageError.value = 'A network error prevented the dependency from being removed.';
    } finally {
        deletingId.value = null;
    }
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString();
}
</script>

<template>
    <div class="task-dependencies-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Task Dependencies</h1>
                    <p class="intro">Record that one task blocks another, so work can only start once its blockers are done.</p>
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
                        :disabled="tasks.length < 2"
                        @click="startCreate"
                    >
                        New dependency
                    </button>
                </div>
            </div>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>
        <p v-if="tasks.length < 2" class="notice notice-info">
            A dependency links two tasks in the same project. Create at least two tasks first.
        </p>

        <section v-if="isCreating" class="dependency-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">Record a dependency</h2>
            <form class="dependency-form" @submit.prevent="saveDependency">
                <div class="form-field">
                    <label for="dependency-project">Project</label>
                    <select id="dependency-project" v-model="form.project_id" required @change="onProjectChange">
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                            {{ project.name }}
                        </option>
                    </select>
                </div>

                <div class="link-row">
                    <div class="form-field">
                        <label for="dependency-blocking">Blocking task — must finish first</label>
                        <select id="dependency-blocking" v-model="form.blocking_task_id" required>
                            <option value="" disabled>Select a task</option>
                            <option
                                v-for="task in formTasks"
                                :key="task.id"
                                :value="task.id"
                                :disabled="String(task.id) === String(form.blocked_task_id)"
                            >
                                {{ task.title }}
                            </option>
                        </select>
                        <small v-if="fieldError('blocking_task_id')" class="field-error">{{ fieldError('blocking_task_id') }}</small>
                    </div>

                    <span class="link-arrow" aria-hidden="true">blocks →</span>

                    <div class="form-field">
                        <label for="dependency-blocked">Blocked task — waits on it</label>
                        <select id="dependency-blocked" v-model="form.blocked_task_id" required>
                            <option value="" disabled>Select a task</option>
                            <option
                                v-for="task in formTasks"
                                :key="task.id"
                                :value="task.id"
                                :disabled="String(task.id) === String(form.blocking_task_id)"
                            >
                                {{ task.title }}
                            </option>
                        </select>
                        <small v-if="fieldError('blocked_task_id')" class="field-error">{{ fieldError('blocked_task_id') }}</small>
                    </div>
                </div>

                <p class="form-hint">
                    Self-links, repeated links and links that would close a cycle are rejected by the server.
                </p>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : 'Record dependency' }}
                    </button>
                    <button class="button button-secondary" type="button" @click="cancelCreate">
                        Done
                    </button>
                </div>
            </form>
        </section>

        <section class="dependency-list" aria-labelledby="dependencies-list-title">
            <div class="list-heading">
                <div>
                    <h2 id="dependencies-list-title">All dependencies</h2>
                    <p>{{ dependencies.length }} link{{ dependencies.length === 1 ? '' : 's' }}</p>
                </div>
                <div class="filters">
                    <label class="visually-hidden" for="dependency-project-filter">Filter by project</label>
                    <select id="dependency-project-filter" v-model="projectFilter" @change="applyProjectFilter">
                        <option value="">All projects</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                            {{ project.name }}
                        </option>
                    </select>
                    <label class="visually-hidden" for="dependency-search">Search by task title</label>
                    <input id="dependency-search" v-model="search" type="search" placeholder="Search task titles">
                </div>
            </div>

            <p v-if="visibleDependencies.length === 0" class="empty-state">
                {{ dependencies.length === 0
                    ? 'No dependencies yet. Record one above to order work inside a project.'
                    : 'No dependencies match this search.' }}
            </p>

            <ul v-else class="dependency-rows">
                <li v-for="dependency in visibleDependencies" :key="dependency.id" class="dependency-row">
                    <div class="dependency-edge">
                        <span class="task-pill task-pill-blocking">{{ dependency.blocking_task_title ?? 'Deleted task' }}</span>
                        <span class="link-arrow" aria-hidden="true">blocks →</span>
                        <span class="task-pill task-pill-blocked">{{ dependency.blocked_task_title ?? 'Deleted task' }}</span>
                    </div>
                    <p class="dependency-meta">
                        {{ dependency.blocking_task_project_name ?? 'No project' }}
                        <span v-if="dependency.created_at">· added {{ formatDate(dependency.created_at) }}</span>
                    </p>
                    <button
                        class="button button-danger"
                        type="button"
                        :disabled="deletingId === dependency.id"
                        @click="deleteDependency(dependency)"
                    >
                        {{ deletingId === dependency.id ? 'Removing…' : 'Remove' }}
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.task-dependencies-page,
.task-dependencies-page * {
    box-sizing: border-box;
}

.task-dependencies-page {
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
.dependency-row,
.dependency-edge,
.filters,
.link-row {
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
.dependency-meta,
.form-hint,
.muted {
    color: #657386;
}

.intro,
.list-heading p {
    margin-bottom: 0;
}

.notice {
    margin: 0 0 20px;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 0.9rem;
}

.notice-error {
    background: #fdecec;
    border: 1px solid #f5c2c7;
    color: #8a1c27;
}

.notice-info {
    background: #e8f2ff;
    border: 1px solid #c9dffb;
    color: #1957a6;
}

.dependency-editor {
    margin-bottom: 32px;
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 22px;
    background: #fff;
}

.dependency-editor h2 {
    margin-bottom: 16px;
    font-size: 1.1rem;
}

.dependency-form {
    display: grid;
    gap: 16px;
}

.link-row {
    align-items: flex-start;
    gap: 12px;
}

.link-row .form-field {
    flex: 1 1 0;
    min-width: 0;
}

.form-field {
    display: grid;
    gap: 6px;
    min-width: 0;
}

.form-field label {
    color: #344256;
    font-size: 0.84rem;
    font-weight: 600;
}

input,
select,
textarea,
.button {
    border-radius: 9px;
    font-size: 0.92rem;
}

input,
select,
textarea {
    width: 100%;
    border: 1px solid #cbd4df;
    padding: 10px 12px;
    background: #fff;
    color: #182230;
}

.link-arrow {
    flex: 0 0 auto;
    padding-top: 30px;
    color: #526174;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
}

.dependency-edge .link-arrow {
    padding-top: 0;
}

.field-error {
    color: #8a1c27;
}

.form-hint,
.form-hint p {
    margin-bottom: 0;
    font-size: 0.82rem;
}

.form-actions {
    gap: 10px;
}

.button {
    border: 1px solid transparent;
    padding: 10px 14px;
    font-weight: 600;
    cursor: pointer;
}

.button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.button-primary {
    background: #1f4f82;
    color: #fff;
}

.button-secondary {
    background: #eef2f7;
    border-color: #cbd4df;
    color: #344256;
}

.button-danger {
    background: #fff;
    border-color: #f5c2c7;
    color: #8a1c27;
}

.list-heading {
    justify-content: space-between;
    margin-bottom: 20px;
}

.list-heading h2 {
    margin-bottom: 5px;
    font-size: 1.1rem;
}

.filters input {
    min-width: 210px;
}

.filters select {
    min-width: 170px;
}

.dependency-rows {
    display: grid;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.dependency-row {
    justify-content: space-between;
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 14px 16px;
    background: #fff;
    gap: 14px;
}

.dependency-edge {
    flex-wrap: wrap;
    min-width: 0;
    gap: 10px;
}

.task-pill {
    overflow-wrap: anywhere;
    border-radius: 999px;
    padding: 6px 11px;
    font-size: 0.88rem;
    font-weight: 600;
}

.task-pill-blocking {
    background: #e8f2ff;
    color: #1957a6;
}

.task-pill-blocked {
    background: #fdf3e3;
    color: #8a5a12;
}

.dependency-meta {
    margin: 0;
    font-size: 0.8rem;
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
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

@media (max-width: 700px) {
    .task-dependencies-page {
        width: min(100% - 24px, 560px);
        padding-top: 28px;
    }

    .page-header,
    .page-header--wrapper,
    .list-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .page-header--actions {
        align-items: flex-start;
        width: 100%;
    }

    .link-row {
        flex-direction: column;
    }

    .link-arrow {
        padding-top: 0;
    }

    .dependency-row {
        align-items: flex-start;
        flex-direction: column;
    }

    .filters {
        width: 100%;
        flex-direction: column;
    }

    .filters input,
    .filters select {
        min-width: 0;
    }
}
</style>
