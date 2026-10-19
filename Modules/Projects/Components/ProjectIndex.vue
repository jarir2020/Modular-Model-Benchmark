<script setup>
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialProjects: {
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

const projects = ref(props.initialProjects.map((project) => ({ ...project })));
const search = ref('');
const statusFilter = ref('all');
const editingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');
const isSaving = ref(false);
const deletingId = ref(null);

const emptyForm = () => ({
    name: '',
    description: '',
    status: 'planned',
    due_date: '',
});

const form = reactive(emptyForm());

const visibleProjects = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    return projects.value.filter((project) => {
        const matchesSearch = query === ''
            || project.name.toLocaleLowerCase().includes(query)
            || (project.description || '').toLocaleLowerCase().includes(query);
        const matchesStatus = statusFilter.value === 'all'
            || project.status === statusFilter.value;

        return matchesSearch && matchesStatus;
    });
});

function startCreate() {
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(project) {
    editingId.value = project.id;
    Object.assign(form, {
        name: project.name,
        description: project.description || '',
        status: project.status,
        due_date: project.due_date || '',
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

function projectUrl(template, id) {
    return template.replace('__PROJECT_ID__', String(id));
}

async function saveProject() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    const isEditing = editingId.value !== null;
    const url = isEditing
        ? projectUrl(props.routes.update, editingId.value)
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
                description: form.description || null,
                status: form.status,
                due_date: form.due_date || null,
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
            pageError.value = data.message || 'The project could not be saved.';
            return;
        }

        if (isEditing) {
            projects.value = projects.value.map((project) => (
                project.id === data.project.id ? data.project : project
            ));
        } else {
            projects.value.unshift(data.project);
        }

        startCreate();
    } catch {
        pageError.value = 'A network error prevented the project from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteProject(project) {
    if (!window.confirm('Delete “' + project.name + '”? This cannot be undone.')) {
        return;
    }

    deletingId.value = project.id;
    pageError.value = '';

    try {
        const response = await fetch(projectUrl(props.routes.destroy, project.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The project could not be deleted.';
            return;
        }

        projects.value = projects.value.filter((item) => item.id !== project.id);

        if (editingId.value === project.id) {
            startCreate();
        }
    } catch {
        pageError.value = 'A network error prevented the project from being deleted.';
    } finally {
        deletingId.value = null;
    }
}

function statusLabel(status) {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function formatDate(value) {
    if (!value) {
        return 'No due date';
    }

    return new Date(value + 'T00:00:00').toLocaleDateString();
}
</script>

<template>
    <div class="projects-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">Project Workbench</p>
                <h1>Projects</h1>
                <p class="intro">Create projects and keep their status and target dates in view.</p>
            </div>
            <button class="button button-primary" type="button" @click="startCreate">
                New project
            </button>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>

        <section class="project-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'Create a project' : 'Edit project' }}</h2>
            <form class="project-form" @submit.prevent="saveProject">
                <div class="form-field">
                    <label for="project-name">Name</label>
                    <input
                        id="project-name"
                        v-model="form.name"
                        maxlength="120"
                        required
                        type="text"
                        autocomplete="off"
                    >
                    <small v-if="fieldError('name')" class="field-error">{{ fieldError('name') }}</small>
                </div>

                <div class="form-field">
                    <label for="project-description">Description</label>
                    <textarea
                        id="project-description"
                        v-model="form.description"
                        maxlength="2000"
                        rows="3"
                    ></textarea>
                    <small v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</small>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="project-status">Status</label>
                        <select id="project-status" v-model="form.status" required>
                            <option value="planned">Planned</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                        <small v-if="fieldError('status')" class="field-error">{{ fieldError('status') }}</small>
                    </div>

                    <div class="form-field">
                        <label for="project-due-date">Due date</label>
                        <input id="project-due-date" v-model="form.due_date" type="date">
                        <small v-if="fieldError('due_date')" class="field-error">{{ fieldError('due_date') }}</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : editingId === null ? 'Create project' : 'Save changes' }}
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

        <section class="project-list" aria-labelledby="list-title">
            <div class="list-heading">
                <div>
                    <h2 id="list-title">All projects</h2>
                    <p>{{ projects.length }} {{ projects.length === 1 ? 'project' : 'projects' }}</p>
                </div>
                <div class="filters">
                    <label class="visually-hidden" for="project-search">Search projects</label>
                    <input
                        id="project-search"
                        v-model="search"
                        type="search"
                        placeholder="Search projects"
                    >
                    <label class="visually-hidden" for="project-status-filter">Filter by status</label>
                    <select id="project-status-filter" v-model="statusFilter">
                        <option value="all">All statuses</option>
                        <option value="planned">Planned</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>

            <p v-if="visibleProjects.length === 0" class="empty-state">
                {{ projects.length === 0 ? 'No projects yet. Create one above to get started.' : 'No projects match these filters.' }}
            </p>

            <div v-else class="project-grid">
                <article v-for="project in visibleProjects" :key="project.id" class="project-card">
                    <div class="card-heading">
                        <h3>{{ project.name }}</h3>
                        <span class="status-badge" :class="'status-' + project.status">
                            {{ statusLabel(project.status) }}
                        </span>
                    </div>
                    <p v-if="project.description" class="description">{{ project.description }}</p>
                    <p v-else class="description muted">No description</p>
                    <p class="due-date">Due: {{ formatDate(project.due_date) }}</p>
                    <div class="card-actions">
                        <button class="button button-secondary" type="button" @click="startEdit(project)">
                            Edit
                        </button>
                        <button
                            class="button button-danger"
                            type="button"
                            :disabled="deletingId === project.id"
                            @click="deleteProject(project)"
                        >
                            {{ deletingId === project.id ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>

<style scoped>
.projects-page,
.projects-page * {
    box-sizing: border-box;
}

.projects-page {
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
.due-date,
.muted {
    color: #657386;
}

.intro,
.list-heading p {
    margin-bottom: 0;
}

.project-editor,
.project-list {
    border: 1px solid #dce2e9;
    border-radius: 16px;
    background: #fff;
    padding: 24px;
    box-shadow: 0 8px 24px rgb(26 43 65 / 5%);
}

.project-editor {
    margin-bottom: 24px;
}

.project-editor h2,
.project-list h2 {
    margin-bottom: 18px;
    font-size: 1.2rem;
}

.project-form {
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

.filters {
    display: flex;
    gap: 10px;
}

.filters input {
    min-width: 210px;
}

.filters select {
    min-width: 150px;
}

.project-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.project-card {
    min-width: 0;
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 18px;
}

.card-heading {
    align-items: flex-start;
}

.card-heading h3 {
    overflow-wrap: anywhere;
    margin-bottom: 12px;
    font-size: 1.05rem;
}

.status-badge {
    flex: 0 0 auto;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 5px 9px;
}

.status-planned {
    background: #eef2f7;
    color: #526174;
}

.status-active {
    background: #e8f2ff;
    color: #1957a6;
}

.status-completed {
    background: #e9f7ef;
    color: #187443;
}

.description {
    min-height: 2.8em;
    margin-bottom: 12px;
    color: #344256;
    line-height: 1.5;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.due-date {
    margin-bottom: 16px;
    font-size: 0.88rem;
}

.card-actions {
    justify-content: flex-start;
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
    .projects-page {
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
    }

    .filters input,
    .filters select {
        min-width: 0;
    }

    .project-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .project-editor,
    .project-list {
        padding: 18px;
    }
}
</style>
