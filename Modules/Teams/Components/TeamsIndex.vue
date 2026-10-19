<script setup>
// Mounted by resources/js/app.js, which matches the `teams` slug from the
// folder name to the #teams-app root and #teams-page-props script.

import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialTeams: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    owner: {
        type: Object,
        default: () => (null),
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

const teams = ref(props.initialTeams.map((team) => ({ ...team })));

const searchTerm = ref(props.filters?.q ?? '');

const isCreating = ref(false);
const editingId = ref(null);
const isSaving = ref(false);
const deletingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');

const emptyForm = () => ({
    name: '',
    description: '',
});

const form = reactive(emptyForm());

const canCreate = computed(() => props.owner !== null);

const sortedTeams = computed(() =>
    [...teams.value].sort((a, b) => a.name.localeCompare(b.name)));

function teamUrl(id) {
    return props.routes.update.replace('__TEAM_ID__', String(id));
}

function destroyUrl(id) {
    return props.routes.destroy.replace('__TEAM_ID__', String(id));
}

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(team) {
    isCreating.value = true;
    editingId.value = team.id;
    Object.assign(form, {
        name: team.name || '',
        description: team.description || '',
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

function applySearch() {
    const url = new URL(window.location.href);

    if (searchTerm.value === '' || searchTerm.value === null) {
        url.searchParams.delete('q');
    } else {
        url.searchParams.set('q', searchTerm.value);
    }

    window.location.href = url.toString();
}

function upsertTeam(team) {
    const index = teams.value.findIndex((entry) => entry.id === team.id);

    if (index === -1) {
        teams.value = [...teams.value, team];
        return;
    }

    teams.value = teams.value.map((entry, position) => (position === index ? team : entry));
}

async function handleResponse(response) {
    if (response.ok) {
        validationErrors.value = {};
        pageError.value = '';
        return true;
    }

    if (response.status === 422) {
        const body = await response.json();
        validationErrors.value = body.errors || {};
        pageError.value = '';
        return false;
    }

    const body = await response.json().catch(() => ({}));

    pageError.value = body.message || `Request failed with status ${response.status}.`;

    return false;
}

async function saveTeam() {
    isSaving.value = true;

    try {
        const isEditing = editingId.value !== null;
        const url = isEditing ? teamUrl(editingId.value) : props.routes.store;
        const method = isEditing ? 'PUT' : 'POST';

        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                name: form.name,
                description: form.description || null,
            }),
        });

        if (! await handleResponse(response)) {
            return;
        }

        const body = await response.json();

        upsertTeam(body.team);
        cancelEdit();
    } finally {
        isSaving.value = false;
    }
}

async function deleteTeam(team) {
    if (! window.confirm(`Delete the team "${team.name}"? This cannot be undone.`)) {
        return;
    }

    deletingId.value = team.id;
    pageError.value = '';

    try {
        const response = await fetch(destroyUrl(team.id), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
        });

        if (! await handleResponse(response)) {
            return;
        }

        teams.value = teams.value.filter((entry) => entry.id !== team.id);

        if (editingId.value === team.id) {
            cancelEdit();
        }
    } finally {
        deletingId.value = null;
    }
}
</script>

<template>
    <div class="teams-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Teams</h1>
                    <p class="intro">Name the teams that own the work in this workspace and see who owns each one.</p>
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
                        :disabled="!canCreate"
                        @click="startCreate"
                    >
                        New team
                    </button>
                </div>
            </div>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>
        <p v-if="!canCreate" class="notice notice-info">
            Teams need an owner. No user account is available in this workspace yet.
        </p>

        <section class="filters" aria-label="Filters">
            <form class="filter-form" @submit.prevent="applySearch">
                <div class="form-field">
                    <label for="team-search">Search teams</label>
                    <input
                        id="team-search"
                        v-model="searchTerm"
                        type="search"
                        placeholder="Team name"
                    >
                </div>
                <button class="button button-secondary" type="submit">Search</button>
                <a v-if="searchTerm" class="button button-link" href="/teams">Clear</a>
            </form>
        </section>

        <section v-if="isCreating" class="team-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'New team' : 'Edit team' }}</h2>
            <form class="team-form" @submit.prevent="saveTeam">
                <div class="form-field">
                    <label for="team-name">Name</label>
                    <input
                        id="team-name"
                        v-model="form.name"
                        type="text"
                        maxlength="120"
                        required
                    >
                    <small v-if="fieldError('name')" class="field-error">{{ fieldError('name') }}</small>
                </div>

                <div class="form-field">
                    <label for="team-description">Description</label>
                    <textarea
                        id="team-description"
                        v-model="form.description"
                        rows="3"
                        maxlength="2000"
                        placeholder="What does this team work on? (optional)"
                    ></textarea>
                    <small v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</small>
                </div>

                <p v-if="owner" class="owner-note">
                    Owner: <strong>{{ owner.name }}</strong>
                </p>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : (editingId === null ? 'Create team' : 'Save changes') }}
                    </button>
                    <button class="button button-secondary" type="button" @click="cancelEdit">Done</button>
                </div>
            </form>
        </section>

        <section class="team-list" aria-label="Teams">
            <p v-if="sortedTeams.length === 0" class="notice notice-info">
                No teams yet. Create the first one to give this workspace its own owner.
            </p>

            <article v-for="team in sortedTeams" :key="team.id" class="team-card">
                <div class="team-card--main">
                    <h2 class="team-name">{{ team.name }}</h2>
                    <p v-if="team.description" class="team-description">{{ team.description }}</p>
                    <p class="team-meta">
                        Owned by <strong>{{ team.owner_name || 'Unknown' }}</strong>
                        <span class="team-members">{{ team.member_count }} members</span>
                    </p>
                </div>
                <div class="team-actions">
                    <button
                        class="button button-secondary"
                        type="button"
                        @click="startEdit(team)"
                    >
                        Edit
                    </button>
                    <button
                        class="button button-danger"
                        type="button"
                        :disabled="deletingId === team.id"
                        @click="deleteTeam(team)"
                    >
                        {{ deletingId === team.id ? 'Deleting…' : 'Delete' }}
                    </button>
                </div>
            </article>
        </section>
    </div>
</template>

<style scoped>
.teams-page {
    max-width: 62rem;
    margin: 0 auto;
    padding: 2rem 1rem 4rem;
}

.page-header {
    margin-bottom: 2rem;
}

.page-header--header {
    margin-bottom: 1rem;
}

.eyebrow {
    margin: 0;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #526174;
}

h1 {
    margin: 0.25rem 0 0.5rem;
    font-size: 1.9rem;
}

.intro {
    margin: 0;
    max-width: 44rem;
    color: #38414e;
}

.module-links {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.module-link {
    color: #2d4a7e;
    font-size: 0.85rem;
    font-weight: 600;
}

.filters {
    margin-bottom: 1.5rem;
}

.filter-form {
    display: flex;
    align-items: flex-end;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-bottom: 0.75rem;
}

.form-field label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #38414e;
}

input,
select,
textarea {
    padding: 0.45rem 0.6rem;
    border: 1px solid #c7cfda;
    border-radius: 6px;
    font: inherit;
    background: #fff;
    color: #1b2027;
}

textarea {
    resize: vertical;
}

.button {
    padding: 0.45rem 0.9rem;
    border: 1px solid transparent;
    border-radius: 6px;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.button-primary {
    background: #2d4a7e;
    color: #fff;
}

.button-secondary {
    background: #fff;
    border-color: #c7cfda;
    color: #2d4a7e;
}

.button-danger {
    background: #a32727;
    color: #fff;
}

.button-link {
    background: none;
    color: #2d4a7e;
    text-decoration: underline;
}

.notice {
    padding: 0.6rem 0.85rem;
    border-radius: 6px;
    margin: 0 0 1rem;
    font-size: 0.9rem;
}

.notice-error {
    background: #fdecec;
    color: #7f1d1d;
}

.notice-info {
    background: #eef2f8;
    color: #2d4a7e;
}

.team-editor {
    border: 1px solid #dbe1ea;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    background: #fff;
}

.team-editor h2 {
    margin-top: 0;
    font-size: 1.1rem;
}

.owner-note {
    margin: 0 0 0.75rem;
    font-size: 0.85rem;
    color: #526174;
}

.form-actions {
    display: flex;
    gap: 0.5rem;
}

.team-list {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.team-card {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    border: 1px solid #dbe1ea;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    background: #fff;
}

.team-name {
    margin: 0 0 0.25rem;
    font-size: 1.1rem;
}

.team-description {
    margin: 0 0 0.5rem;
    color: #38414e;
    font-size: 0.92rem;
}

.team-meta {
    margin: 0;
    font-size: 0.82rem;
    color: #526174;
}

.team-members {
    margin-left: 0.75rem;
}

.team-actions {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

.field-error {
    color: #a32727;
    font-size: 0.78rem;
}
</style>