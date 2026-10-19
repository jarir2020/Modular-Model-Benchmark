<script setup>
// Mounted by resources/js/app.js, which matches the `roles` slug from the
// folder name to the #roles-app root and #roles-page-props script.

import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialRoles: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    teams: {
        type: Array,
        default: () => [],
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    actingUserId: {
        type: Number,
        default: null,
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

const memberStatusLabels = {
    invited: 'Invited',
    active: 'Active',
    suspended: 'Suspended',
};

const roles = ref(props.initialRoles.map((role) => ({
    ...role,
    assignments: [...(role.assignments || [])],
})));

const teamFilter = ref(props.filters?.team_id ?? '');
const searchTerm = ref(props.filters?.q ?? '');

const isCreating = ref(false);
const editingId = ref(null);
const isSaving = ref(false);
const savingId = ref(null);
const deletingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');

/**
 * Per-role assignee picks, keyed by role id. Seeded empty; the server rules
 * (same team, not already holding the role) decide what each list offers.
 */
const assignDrafts = reactive({});

const assignableTeams = computed(() => props.teams.filter((team) => team.is_owned === true));

const canCreate = computed(() => assignableTeams.value.length > 0);

const teamById = (teamId) => props.teams.find((team) => team.id === teamId) || null;

const isOwnedTeam = (teamId) => teamById(teamId)?.is_owned === true;

/**
 * Roles grouped by team in alphabetical order, with a stable name order
 * inside each group. Teams whose roles are filtered out drop away with them.
 */
const groupedRoles = computed(() => {
    const groups = new Map();

    roles.value.forEach((role) => {
        if (! groups.has(role.team_id)) {
            groups.set(role.team_id, {
                team_id: role.team_id,
                team_name: role.team_name || 'Unknown team',
                is_owned: isOwnedTeam(role.team_id),
                roles: [],
            });
        }

        groups.get(role.team_id).roles.push(role);
    });

    return [...groups.values()]
        .sort((a, b) => a.team_name.localeCompare(b.team_name))
        .map((group) => ({
            ...group,
            roles: [...group.roles].sort((a, b) => a.name.localeCompare(b.name)),
        }));
});

const roleTotal = computed(() => roles.value.length);

const assignmentTotal = computed(
    () => roles.value.reduce((sum, role) => sum + (role.assignment_count || 0), 0),
);

/**
 * Members of the role's own team that do not hold this role yet — the server
 * refuses every other pairing, so the picker only ever offers valid ones.
 */
function candidatesFor(role) {
    const held = new Set(role.assignments.map((assignment) => assignment.team_member_id));

    return props.teamMembers.filter(
        (member) => member.team_id === role.team_id && ! held.has(member.id),
    );
}

function emptyForm() {
    const team = assignableTeams.value[0] || null;

    return {
        team_id: team ? String(team.id) : '',
        name: '',
        description: '',
    };
}

const form = reactive(emptyForm());

function roleUrl(id) {
    return props.routes.update.replace('__ROLE_ID__', String(id));
}

function destroyUrl(id) {
    return props.routes.destroy.replace('__ROLE_ID__', String(id));
}

function assignUrl(id) {
    return props.routes.assign.replace('__ROLE_ID__', String(id));
}

function unassignUrl(roleId, memberRowId) {
    return props.routes.unassign
        .replace('__ROLE_ID__', String(roleId))
        .replace('__MEMBER_ID__', String(memberRowId));
}

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(role) {
    isCreating.value = true;
    editingId.value = role.id;
    Object.assign(form, {
        team_id: String(role.team_id),
        name: role.name || '',
        description: role.description || '',
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

function firstErrorMessage() {
    return Object.values(validationErrors.value).flat()[0] || '';
}

function setParam(url, key, value) {
    if (value === '' || value === null || value === undefined) {
        url.searchParams.delete(key);

        return;
    }

    url.searchParams.set(key, value);
}

function applyFilters() {
    const url = new URL(window.location.href);

    setParam(url, 'team_id', teamFilter.value);
    setParam(url, 'q', searchTerm.value);

    window.location.href = url.toString();
}

function upsertRole(role) {
    const index = roles.value.findIndex((entry) => entry.id === role.id);

    roles.value = index === -1
        ? [...roles.value, { ...role, assignments: [...(role.assignments || [])] }]
        : roles.value.map((entry, position) => (position === index
            ? { ...role, assignments: [...(role.assignments || [])] }
            : entry));

    delete assignDrafts[role.id];
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

async function saveRole() {
    isSaving.value = true;

    try {
        const isEditing = editingId.value !== null;
        const url = isEditing ? roleUrl(editingId.value) : props.routes.store;
        const method = isEditing ? 'PUT' : 'POST';

        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                // The update endpoint keeps a role in its own team, so the
                // team is only sent when creating.
                ...(isEditing ? {} : { team_id: form.team_id === '' ? null : Number(form.team_id) }),
                name: form.name,
                description: form.description || null,
            }),
        });

        if (! await handleResponse(response)) {
            return;
        }

        const body = await response.json();

        upsertRole(body.role);
        cancelEdit();
    } finally {
        isSaving.value = false;
    }
}

async function deleteRole(role) {
    const assignedNote = role.assignment_count > 0
        ? ` Its ${role.assignment_count} assignment${role.assignment_count === 1 ? '' : 's'} will be removed too.`
        : '';

    if (! window.confirm(`Delete the role "${role.name}" from "${role.team_name || 'this team'}"?${assignedNote}`)) {
        return;
    }

    deletingId.value = role.id;
    pageError.value = '';

    try {
        const response = await fetch(destroyUrl(role.id), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
        });

        if (! await handleResponse(response)) {
            return;
        }

        roles.value = roles.value.filter((entry) => entry.id !== role.id);
        delete assignDrafts[role.id];

        if (editingId.value === role.id) {
            cancelEdit();
        }
    } finally {
        deletingId.value = null;
    }
}

async function assignMember(role) {
    const teamMemberId = assignDrafts[role.id];

    if (! teamMemberId) {
        return;
    }

    savingId.value = role.id;
    pageError.value = '';

    try {
        const response = await fetch(assignUrl(role.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({ team_member_id: Number(teamMemberId) }),
        });

        if (! await handleResponse(response)) {
            pageError.value = fieldError('team_member_id')
                || pageError.value
                || 'That role was not assigned.';

            return;
        }

        const body = await response.json();

        upsertRole(body.role);
    } finally {
        savingId.value = null;
    }
}

async function unassignMember(role, assignment) {
    const label = assignment.user_name || 'this member';

    if (! window.confirm(`Remove "${role.name}" from ${label}?`)) {
        return;
    }

    savingId.value = role.id;
    pageError.value = '';

    try {
        const response = await fetch(unassignUrl(role.id, assignment.team_member_id), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
        });

        if (! await handleResponse(response)) {
            return;
        }

        const body = await response.json();

        upsertRole(body.role);
    } finally {
        savingId.value = null;
    }
}

function memberStatusLabel(status) {
    return memberStatusLabels[status] || status;
}

function formatDate(value) {
    if (! value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString();
}
</script>

<template>
    <div class="roles-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Roles</h1>
                    <p class="intro">
                        Define the roles your teams use, then hand them to the members who fill
                        those shoes. Role names stay unique within each team.
                    </p>
                </div>
                <div class="page-header--actions">
                    <nav class="module-links" aria-label="Modules">
                        <a class="module-link" href="/projects">Projects</a>
                        <a class="module-link" href="/tasks">Tasks</a>
                        <a class="module-link" href="/teams">Teams</a>
                        <a class="module-link" href="/team-members">Team members</a>
                    </nav>
                    <button
                        v-if="!isCreating"
                        class="button button-primary"
                        type="button"
                        :disabled="!canCreate"
                        @click="startCreate"
                    >
                        New role
                    </button>
                </div>
            </div>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>
        <p v-if="!canCreate" class="notice notice-info">
            Roles belong to teams you own. No team with you as its owner exists yet — create one
            on the Teams page first.
        </p>

        <section class="filters" aria-label="Filters">
            <form class="filter-form" @submit.prevent="applyFilters">
                <div class="form-field">
                    <label for="role-team-filter">Team</label>
                    <select id="role-team-filter" v-model="teamFilter">
                        <option value="">All visible teams</option>
                        <option v-for="team in teams" :key="team.id" :value="String(team.id)">
                            {{ team.name }}
                        </option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="role-search">Search</label>
                    <input
                        id="role-search"
                        v-model="searchTerm"
                        type="search"
                        placeholder="Role name, description, or team"
                    >
                </div>

                <button class="button button-secondary" type="submit">Apply</button>
                <a class="button button-link" :href="routes.index">Clear</a>
            </form>
        </section>

        <section v-if="isCreating" class="role-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'New role' : 'Edit role' }}</h2>

            <form class="role-form" @submit.prevent="saveRole">
                <div class="form-field">
                    <label for="role-team">Team</label>
                    <select
                        id="role-team"
                        v-model="form.team_id"
                        :disabled="editingId !== null"
                        :required="editingId === null"
                    >
                        <option value="" disabled>Choose a team</option>
                        <option
                            v-for="team in assignableTeams"
                            :key="team.id"
                            :value="String(team.id)"
                        >
                            {{ team.name }}
                        </option>
                    </select>
                    <small v-if="editingId !== null" class="field-hint">
                        A role stays with the team that defines it.
                    </small>
                    <small v-if="fieldError('team_id')" class="field-error">{{ fieldError('team_id') }}</small>
                </div>

                <div class="form-field">
                    <label for="role-name">Name</label>
                    <input
                        id="role-name"
                        v-model="form.name"
                        type="text"
                        maxlength="120"
                        required
                        placeholder="e.g. Designer"
                    >
                    <small v-if="fieldError('name')" class="field-error">{{ fieldError('name') }}</small>
                </div>

                <div class="form-field form-field--wide">
                    <label for="role-description">Description</label>
                    <textarea
                        id="role-description"
                        v-model="form.description"
                        rows="3"
                        maxlength="2000"
                        placeholder="What does this role do? (optional)"
                    ></textarea>
                    <small v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</small>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : (editingId === null ? 'Create role' : 'Save changes') }}
                    </button>
                    <button class="button button-secondary" type="button" @click="cancelEdit">Done</button>
                </div>
            </form>
        </section>

        <section class="role-list" aria-label="Roles">
            <p class="list-summary">
                {{ roleTotal }} role<span v-if="roleTotal !== 1">s</span>
                · {{ assignmentTotal }} assignment<span v-if="assignmentTotal !== 1">s</span>
            </p>

            <p v-if="groupedRoles.length === 0" class="notice notice-info">
                Nothing to show. Roles of teams you own — and of teams you belong to — appear
                here.
            </p>

            <article v-for="group in groupedRoles" :key="group.team_id" class="team-group">
                <header class="team-group--header">
                    <h2>{{ group.team_name }}</h2>
                    <p class="team-group--meta">
                        {{ group.roles.length }} role<span v-if="group.roles.length !== 1">s</span>
                        <span v-if="group.is_owned" class="owner-tag">you own this team</span>
                    </p>
                </header>

                <div v-for="role in group.roles" :key="role.id" class="role-card">
                    <div class="role-card--main">
                        <h3 class="role-name">{{ role.name }}</h3>
                        <p v-if="role.description" class="role-description">{{ role.description }}</p>

                        <p class="role-meta">
                            <span class="assignment-badge">
                                {{ role.assignment_count }} member<span v-if="role.assignment_count !== 1">s</span>
                            </span>
                            <span v-if="role.created_at">Created {{ formatDate(role.created_at) }}</span>
                        </p>

                        <ul v-if="role.assignments.length > 0" class="assignment-list">
                            <li
                                v-for="assignment in role.assignments"
                                :key="assignment.team_member_id"
                                class="assignment-item"
                            >
                                <span class="assignment-person">
                                    {{ assignment.user_name || 'Unknown user' }}
                                    <span v-if="assignment.user_email" class="assignment-email">
                                        {{ assignment.user_email }}
                                    </span>
                                </span>
                                <span
                                    v-if="assignment.member_status"
                                    class="status-badge"
                                    :class="'status-' + assignment.member_status"
                                >
                                    {{ memberStatusLabel(assignment.member_status) }}
                                </span>
                                <span v-if="assignment.assigned_at" class="assignment-date">
                                    since {{ formatDate(assignment.assigned_at) }}
                                </span>
                                <button
                                    v-if="group.is_owned"
                                    class="button button-danger button-small"
                                    type="button"
                                    :disabled="savingId === role.id"
                                    @click="unassignMember(role, assignment)"
                                >
                                    Remove
                                </button>
                            </li>
                        </ul>
                        <p v-else class="assignment-empty">No member holds this role yet.</p>
                    </div>

                    <div v-if="group.is_owned" class="role-actions">
                        <div class="assign-row">
                            <label class="visually-hidden" :for="'role-assign-' + role.id">
                                Assign {{ role.name }}
                            </label>
                            <select
                                :id="'role-assign-' + role.id"
                                v-model="assignDrafts[role.id]"
                                :disabled="savingId === role.id"
                            >
                                <option value="">Choose a member</option>
                                <option
                                    v-for="member in candidatesFor(role)"
                                    :key="member.id"
                                    :value="String(member.id)"
                                >
                                    {{ member.user_name || 'Unknown' }} — {{ member.status }}
                                </option>
                            </select>
                            <button
                                class="button button-secondary"
                                type="button"
                                :disabled="savingId === role.id || ! assignDrafts[role.id]"
                                @click="assignMember(role)"
                            >
                                {{ savingId === role.id ? 'Saving…' : 'Assign' }}
                            </button>
                        </div>

                        <div class="edit-row">
                            <button
                                class="button button-secondary"
                                type="button"
                                @click="startEdit(role)"
                            >
                                Edit
                            </button>
                            <button
                                class="button button-danger"
                                type="button"
                                :disabled="deletingId === role.id"
                                @click="deleteRole(role)"
                            >
                                {{ deletingId === role.id ? 'Deleting…' : 'Delete' }}
                            </button>
                        </div>
                    </div>

                    <p v-else class="role-locked">Only the team owner can manage this role.</p>
                </div>
            </article>
        </section>
    </div>
</template>

<style scoped>
.roles-page {
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

.module-link:hover {
    text-decoration: underline;
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

.form-field--wide {
    grid-column: 1 / -1;
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

.button-small {
    padding: 0.25rem 0.6rem;
    font-size: 0.8rem;
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

.role-editor {
    border: 1px solid #dbe1ea;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    background: #fff;
}

.role-editor h2 {
    margin-top: 0;
    font-size: 1.1rem;
}

.role-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 0 1rem;
}

.form-actions {
    display: flex;
    gap: 0.5rem;
    grid-column: 1 / -1;
}

.list-summary {
    margin: 0 0 0.75rem;
    font-size: 0.82rem;
    color: #526174;
}

.role-list {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.team-group {
    border: 1px solid #dbe1ea;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    background: #fff;
}

.team-group--header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    border-bottom: 1px solid #eaeef4;
    padding-bottom: 0.5rem;
    margin-bottom: 0.75rem;
}

.team-group--header h2 {
    margin: 0;
    font-size: 1.1rem;
}

.team-group--meta {
    margin: 0;
    font-size: 0.82rem;
    color: #526174;
}

.owner-tag {
    margin-left: 0.6rem;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    background: #e6f4ea;
    color: #1b5e20;
    font-size: 0.72rem;
    font-weight: 600;
}

.role-card {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f4f8;
}

.role-card:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.role-card--main {
    flex: 1;
    min-width: 0;
}

.role-name {
    margin: 0 0 0.25rem;
    font-size: 1rem;
}

.role-description {
    margin: 0 0 0.4rem;
    font-size: 0.9rem;
    color: #38414e;
}

.role-meta {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0 0 0.5rem;
    font-size: 0.8rem;
    color: #526174;
}

.assignment-badge {
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    background: #eef2f8;
    color: #2d4a7e;
    font-size: 0.72rem;
    font-weight: 700;
}

.assignment-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.assignment-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    font-size: 0.85rem;
    padding: 0.3rem 0.5rem;
    border: 1px solid #eaeef4;
    border-radius: 6px;
    background: #fafbfd;
}

.assignment-person {
    font-weight: 600;
    color: #1b2027;
}

.assignment-email {
    margin-left: 0.4rem;
    font-weight: 400;
    color: #526174;
}

.assignment-date {
    font-size: 0.75rem;
    color: #526174;
}

.assignment-empty {
    margin: 0;
    font-size: 0.8rem;
    color: #526174;
    font-style: italic;
}

.status-badge {
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}

.status-active {
    background: #e6f4ea;
    color: #1b5e20;
}

.status-invited {
    background: #eef2f8;
    color: #2d4a7e;
}

.status-suspended {
    background: #fdecec;
    color: #7f1d1d;
}

.role-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.5rem;
    flex-shrink: 0;
}

.assign-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.assign-row select {
    max-width: 15rem;
}

.edit-row {
    display: flex;
    gap: 0.5rem;
}

.role-locked {
    margin: 0;
    font-size: 0.78rem;
    color: #526174;
    flex-shrink: 0;
    max-width: 12rem;
    text-align: right;
}

.field-hint {
    color: #526174;
    font-size: 0.78rem;
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
