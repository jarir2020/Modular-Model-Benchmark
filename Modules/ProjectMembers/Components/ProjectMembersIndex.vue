<script setup>
// Mounted by resources/js/app.js, which matches the `project-members` slug from
// the folder name to the #project-members-app root and #project-members-page-props script.

import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    initialMembers: {
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
    users: {
        type: Array,
        default: () => [],
    },
    accessLevels: {
        type: Array,
        default: () => ['viewer', 'editor', 'admin'],
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

const accessLabels = {
    viewer: 'Viewer',
    editor: 'Editor',
    admin: 'Admin',
};

const members = ref(props.initialMembers.map((member) => ({ ...member })));
// Mirrors the server's project payload locally, so counts and manageability
// stay honest after a create or remove without a full page reload.
const projects = ref(props.projects.map((project) => ({ ...project })));

const projectFilter = ref(props.filters?.project_id ?? '');
const levelFilter = ref(props.filters?.access_level ?? '');
const searchTerm = ref(props.filters?.q ?? '');

const isCreating = ref(false);
const isSaving = ref(false);
const savingId = ref(null);
const deletingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');

/**
 * Per-row level selects. Seeded from the server payload and refreshed whenever
 * a row is replaced, so the select always shows the stored level.
 */
const levelDrafts = reactive({});

function seedDrafts(list) {
    list.forEach((member) => {
        levelDrafts[member.id] = member.access_level;
    });
}

seedDrafts(members.value);

const manageableProjects = computed(() => projects.value.filter((project) => project.can_manage === true));
const eligibleUsers = computed(() => props.users.filter((user) => user.eligible === true));
const restrictedUserCount = computed(() => props.users.length - eligibleUsers.value.length);

const canAddMembers = computed(() => manageableProjects.value.length > 0 && eligibleUsers.value.length > 0);

const selectedProject = computed(
    () => projects.value.find((project) => String(project.id) === form.project_id) || null,
);

function isUnclaimed(project) {
    return project?.is_unclaimed === true;
}

function emptyForm() {
    const project = manageableProjects.value[0] || null;
    // A project nobody has joined is claimed by you, at the admin level; the
    // server enforces both halves of that rule.
    const self = props.users.find((user) => user.id === props.actingUserId) || null;
    const defaultUser = isUnclaimed(project) ? self : eligibleUsers.value[0] || null;

    return {
        project_id: project ? String(project.id) : '',
        user_id: defaultUser ? String(defaultUser.id) : '',
        access_level: isUnclaimed(project) ? 'admin' : 'viewer',
    };
}

const form = reactive(emptyForm());

watch(
    () => form.project_id,
    () => {
        const project = selectedProject.value;

        if (! isUnclaimed(project)) {
            return;
        }

        form.access_level = 'admin';

        if (props.actingUserId !== null) {
            form.user_id = String(props.actingUserId);
        }
    },
);

/**
 * Memberships grouped by project in alphabetical order, with a stable name
 * order inside each group.
 */
const groupedMembers = computed(() => {
    const groups = new Map();

    members.value.forEach((member) => {
        if (! groups.has(member.project_id)) {
            groups.set(member.project_id, {
                project_id: member.project_id,
                project_name: member.project_name || 'Unknown project',
                members: [],
            });
        }

        groups.get(member.project_id).members.push(member);
    });

    return [...groups.values()]
        .sort((a, b) => a.project_name.localeCompare(b.project_name))
        .map((group) => ({
            ...group,
            members: [...group.members].sort((a, b) => (a.user_name || '').localeCompare(b.user_name || '')),
        }));
});

const memberTotal = computed(() => members.value.length);

function accessLabel(level) {
    return accessLabels[level] || level;
}

function formatDate(value) {
    if (! value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString();
}

function projectById(projectId) {
    return projects.value.find((project) => project.id === projectId) || null;
}

function isOwnRow(member) {
    return props.actingUserId !== null && member.user_id === props.actingUserId;
}

function isAdminOn(projectId) {
    return projectById(projectId)?.is_admin === true;
}

/**
 * Mirrors the server rule: a project admin manages every row on their project,
 * and any member may remove themself.
 */
function canManage(member) {
    return isAdminOn(member.project_id) || isOwnRow(member);
}

function memberUrl(id) {
    return props.routes.update.replace('__MEMBER_ID__', String(id));
}

function destroyUrl(id) {
    return props.routes.destroy.replace('__MEMBER_ID__', String(id));
}

function startCreate() {
    isCreating.value = true;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function cancelEdit() {
    isCreating.value = false;
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

    setParam(url, 'project_id', projectFilter.value);
    setParam(url, 'access_level', levelFilter.value);
    setParam(url, 'q', searchTerm.value);

    window.location.href = url.toString();
}

function upsertMember(member) {
    const index = members.value.findIndex((entry) => entry.id === member.id);

    members.value = index === -1
        ? [...members.value, member]
        : members.value.map((entry, position) => (position === index ? member : entry));

    levelDrafts[member.id] = member.access_level;
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

/**
 * Grow the local project record after a successful add: one more member, no
 * longer unclaimed, and the actor administers it if they were the one added.
 */
function noteProjectGrew(member) {
    const project = projectById(member.project_id);

    if (! project) {
        return;
    }

    project.member_count += 1;
    project.is_unclaimed = false;

    if (member.user_id === props.actingUserId && member.access_level === 'admin') {
        project.is_admin = true;
    }

    project.can_manage = project.is_admin || project.is_unclaimed;
}

async function saveMember() {
    isSaving.value = true;

    try {
        const response = await fetch(props.routes.store, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                project_id: form.project_id === '' ? null : Number(form.project_id),
                user_id: form.user_id === '' ? null : Number(form.user_id),
                access_level: form.access_level,
            }),
        });

        if (! await handleResponse(response)) {
            return;
        }

        const body = await response.json();

        noteProjectGrew(body.member);
        upsertMember(body.member);
        cancelEdit();
    } finally {
        isSaving.value = false;
    }
}

async function updateLevel(member) {
    const access_level = levelDrafts[member.id];

    if (access_level === member.access_level) {
        return;
    }

    savingId.value = member.id;
    pageError.value = '';

    try {
        const response = await fetch(memberUrl(member.id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({ access_level }),
        });

        if (! await handleResponse(response)) {
            levelDrafts[member.id] = member.access_level;
            pageError.value = fieldError('access_level')
                || pageError.value
                || 'That access level was not saved.';

            return;
        }

        const body = await response.json();

        upsertMember(body.member);

        // The actor's own promotion or demotion changes what they administer.
        const project = projectById(body.member.project_id);

        if (project && body.member.user_id === props.actingUserId) {
            project.is_admin = body.member.access_level === 'admin';
            project.can_manage = project.is_admin || project.is_unclaimed;
        }
    } finally {
        savingId.value = null;
    }
}

async function deleteMember(member) {
    const label = member.user_name || 'this member';
    const question = isOwnRow(member) && ! isAdminOn(member.project_id)
        ? 'Leave this project?'
        : `Remove ${label} from "${member.project_name || 'this project'}"?`;

    if (! window.confirm(question)) {
        return;
    }

    deletingId.value = member.id;
    pageError.value = '';

    try {
        const response = await fetch(destroyUrl(member.id), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': props.csrfToken,
                Accept: 'application/json',
            },
        });

        if (! await handleResponse(response)) {
            pageError.value = firstErrorMessage()
                || pageError.value
                || 'That membership could not be removed.';

            return;
        }

        members.value = members.value.filter((entry) => entry.id !== member.id);
        delete levelDrafts[member.id];
        noteProjectShrank(member);
    } finally {
        deletingId.value = null;
    }
}

/**
 * Shrink the local project record after a successful remove. An emptied
 * project returns to the claimable state; the actor leaving a project that
 * still has members takes that project out of their read scope entirely.
 */
function noteProjectShrank(member) {
    const project = projectById(member.project_id);

    if (! project) {
        return;
    }

    project.member_count = Math.max(0, project.member_count - 1);

    if (project.member_count === 0) {
        project.is_unclaimed = true;
        project.is_admin = false;
        project.can_manage = true;

        return;
    }

    if (member.user_id === props.actingUserId) {
        projects.value = projects.value.filter((entry) => entry.id !== project.id);
        members.value = members.value.filter((entry) => entry.project_id !== project.id);
    }
}
</script>

<template>
    <div class="project-members-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Project members</h1>
                    <p class="intro">
                        Put people on a project at the access level their work needs, adjust that
                        level as their role changes, and remove them when the work ends.
                    </p>
                </div>
                <div class="page-header--actions">
                    <nav class="module-links" aria-label="Modules">
                        <a class="module-link" href="/projects">Projects</a>
                        <a class="module-link" href="/tasks">Tasks</a>
                        <a class="module-link" href="/teams">Teams</a>
                    </nav>
                    <button
                        v-if="!isCreating"
                        class="button button-primary"
                        type="button"
                        :disabled="!canAddMembers"
                        @click="startCreate"
                    >
                        Add member
                    </button>
                </div>
            </div>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>
        <p v-if="!canAddMembers" class="notice notice-info">
            Adding a member needs a project you administer — or one nobody has joined yet — and
            another user account in this workspace.
        </p>

        <section class="filters" aria-label="Filters">
            <form class="filter-form" @submit.prevent="applyFilters">
                <div class="form-field">
                    <label for="member-project-filter">Project</label>
                    <select id="member-project-filter" v-model="projectFilter">
                        <option value="">All visible projects</option>
                        <option v-for="project in projects" :key="project.id" :value="String(project.id)">
                            {{ project.name }}
                        </option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="member-level-filter">Access</label>
                    <select id="member-level-filter" v-model="levelFilter">
                        <option value="">Any level</option>
                        <option v-for="level in accessLevels" :key="level" :value="level">
                            {{ accessLabel(level) }}
                        </option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="member-search">Search</label>
                    <input
                        id="member-search"
                        v-model="searchTerm"
                        type="search"
                        placeholder="Member name, email, or project"
                    >
                </div>

                <button class="button button-secondary" type="submit">Apply</button>
                <a class="button button-link" :href="routes.index">Clear</a>
            </form>
        </section>

        <section v-if="isCreating" class="member-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">Add a member</h2>

            <form class="member-form" @submit.prevent="saveMember">
                <div class="form-field">
                    <label for="member-project">Project</label>
                    <select id="member-project" v-model="form.project_id" required>
                        <option value="" disabled>Choose a project</option>
                        <option v-for="project in manageableProjects" :key="project.id" :value="String(project.id)">
                            {{ project.name }}<template v-if="project.is_unclaimed"> — no members yet</template>
                        </option>
                    </select>
                    <small v-if="fieldError('project_id')" class="field-error">{{ fieldError('project_id') }}</small>
                </div>

                <div class="form-field">
                    <label for="member-user">User</label>
                    <select id="member-user" v-model="form.user_id" required :disabled="isUnclaimed(selectedProject)">
                        <option value="" disabled>Choose a user</option>
                        <option
                            v-for="user in users"
                            :key="user.id"
                            :value="String(user.id)"
                            :disabled="!user.eligible"
                        >
                            {{ user.name }} — {{ user.email }}
                        </option>
                    </select>
                    <small v-if="isUnclaimed(selectedProject)" class="field-hint">
                        A project with no members is claimed by you, as its admin.
                    </small>
                    <small v-else-if="restrictedUserCount > 0" class="field-hint">
                        Users outside your teams cannot be added.
                    </small>
                    <small v-if="fieldError('user_id')" class="field-error">{{ fieldError('user_id') }}</small>
                </div>

                <div class="form-field">
                    <label for="member-level">Access level</label>
                    <select id="member-level" v-model="form.access_level">
                        <option v-for="level in accessLevels" :key="level" :value="level">
                            {{ accessLabel(level) }}
                        </option>
                    </select>
                    <small v-if="fieldError('access_level')" class="field-error">{{ fieldError('access_level') }}</small>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Adding…' : 'Add member' }}
                    </button>
                    <button class="button button-secondary" type="button" @click="cancelEdit">Done</button>
                </div>
            </form>
        </section>

        <section class="member-list" aria-label="Project members">
            <p class="list-summary">{{ memberTotal }} membership<span v-if="memberTotal !== 1">s</span> shown</p>

            <p v-if="groupedMembers.length === 0" class="notice notice-info">
                Nothing to show. Memberships of projects you belong to — and rosters nobody has
                claimed yet — appear here.
            </p>

            <article v-for="group in groupedMembers" :key="group.project_id" class="project-group">
                <header class="project-group--header">
                    <h2>{{ group.project_name }}</h2>
                    <p class="project-group--meta">
                        {{ group.members.length }} member<span v-if="group.members.length !== 1">s</span>
                        <span v-if="isAdminOn(group.project_id)" class="admin-tag">you administer this project</span>
                    </p>
                </header>

                <div v-for="member in group.members" :key="member.id" class="member-card">
                    <div class="member-card--main">
                        <h3 class="member-name">
                            {{ member.user_name || 'Unknown user' }}
                            <span v-if="isOwnRow(member)" class="you-tag">you</span>
                        </h3>
                        <p v-if="member.user_email" class="member-email">{{ member.user_email }}</p>
                        <p class="member-meta">
                            <span class="level-badge" :class="'level-' + member.access_level">
                                {{ accessLabel(member.access_level) }}
                            </span>
                            <span v-if="member.created_at">Added {{ formatDate(member.created_at) }}</span>
                        </p>
                    </div>

                    <div v-if="canManage(member)" class="member-actions">
                        <template v-if="isAdminOn(member.project_id)">
                            <label class="visually-hidden" :for="'member-level-' + member.id">
                                Access level for {{ member.user_name || 'member' }}
                            </label>
                            <select
                                :id="'member-level-' + member.id"
                                v-model="levelDrafts[member.id]"
                                :disabled="savingId === member.id"
                            >
                                <option v-for="level in accessLevels" :key="level" :value="level">
                                    {{ accessLabel(level) }}
                                </option>
                            </select>
                            <button
                                class="button button-secondary"
                                type="button"
                                :disabled="savingId === member.id || levelDrafts[member.id] === member.access_level"
                                @click="updateLevel(member)"
                            >
                                {{ savingId === member.id ? 'Saving…' : 'Save access' }}
                            </button>
                        </template>

                        <button
                            class="button button-danger"
                            type="button"
                            :disabled="deletingId === member.id"
                            @click="deleteMember(member)"
                        >
                            {{ deletingId === member.id ? 'Removing…' : (isOwnRow(member) && !isAdminOn(member.project_id) ? 'Leave project' : 'Remove') }}
                        </button>
                    </div>

                    <p v-else class="member-locked">A project admin can change this membership.</p>
                </div>
            </article>
        </section>
    </div>
</template>

<style scoped>
.project-members-page {
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

.member-editor {
    border: 1px solid #dbe1ea;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    background: #fff;
}

.member-editor h2 {
    margin-top: 0;
    font-size: 1.1rem;
}

.member-form {
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

.member-list {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.project-group {
    border: 1px solid #dbe1ea;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    background: #fff;
}

.project-group--header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    border-bottom: 1px solid #eaeef4;
    padding-bottom: 0.5rem;
    margin-bottom: 0.75rem;
}

.project-group--header h2 {
    margin: 0;
    font-size: 1.1rem;
}

.project-group--meta {
    margin: 0;
    font-size: 0.82rem;
    color: #526174;
}

.admin-tag {
    margin-left: 0.6rem;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    background: #e6f4ea;
    color: #1b5e20;
    font-size: 0.72rem;
    font-weight: 600;
}

.member-card {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f4f8;
}

.member-card:last-child {
    border-bottom: none;
}

.member-name {
    margin: 0 0 0.15rem;
    font-size: 0.98rem;
}

.member-email {
    margin: 0 0 0.35rem;
    font-size: 0.85rem;
    color: #526174;
}

.member-meta {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0;
    font-size: 0.8rem;
    color: #526174;
}

.level-badge {
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}

.level-admin {
    background: #e6f4ea;
    color: #1b5e20;
}

.level-editor {
    background: #eef2f8;
    color: #2d4a7e;
}

.level-viewer {
    background: #f1f4f8;
    color: #526174;
}

.you-tag {
    margin-left: 0.4rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: #526174;
}

.member-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.member-locked {
    margin: 0;
    font-size: 0.78rem;
    color: #526174;
    flex-shrink: 0;
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
