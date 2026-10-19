<script setup>
// Mounted by resources/js/app.js, which matches the `team-members` slug from the
// folder name to the #team-members-app root and #team-members-page-props script.

import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialMembers: {
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
    users: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => ['invited', 'active', 'suspended'],
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

const statusLabels = {
    invited: 'Invited',
    active: 'Active',
    suspended: 'Suspended',
};

const members = ref(props.initialMembers.map((member) => ({ ...member })));

const teamFilter = ref(props.filters?.team_id ?? '');
const statusFilter = ref(props.filters?.status ?? '');
const searchTerm = ref(props.filters?.q ?? '');

const isCreating = ref(false);
const isSaving = ref(false);
const savingId = ref(null);
const deletingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');

/**
 * Per-row state selects. Seeded from the server payload and refreshed whenever
 * a row is replaced, so the select always shows the stored state.
 */
const statusDrafts = reactive({});

function seedDrafts(list) {
    list.forEach((member) => {
        statusDrafts[member.id] = member.status;
    });
}

seedDrafts(members.value);

const ownedTeams = computed(() => props.teams.filter((team) => team.is_owned === true));

const canAddMembers = computed(() => ownedTeams.value.length > 0 && props.users.length > 0);

const emptyForm = () => ({
    team_id: ownedTeams.value[0] ? String(ownedTeams.value[0].id) : '',
    user_id: props.users[0] ? String(props.users[0].id) : '',
    status: 'active',
});

const form = reactive(emptyForm());

/**
 * Memberships grouped by team in the order the server returned them, with a
 * stable name order inside each group.
 */
const groupedMembers = computed(() => {
    const groups = new Map();

    members.value.forEach((member) => {
        if (! groups.has(member.team_id)) {
            groups.set(member.team_id, {
                team_id: member.team_id,
                team_name: member.team_name || 'Unknown team',
                members: [],
            });
        }

        groups.get(member.team_id).members.push(member);
    });

    return [...groups.values()].map((group) => ({
        ...group,
        members: [...group.members].sort((a, b) => (a.user_name || '').localeCompare(b.user_name || '')),
    }));
});

const memberTotal = computed(() => members.value.length);

function statusLabel(status) {
    return statusLabels[status] || status;
}

function formatDate(value) {
    if (! value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString();
}

function isTeamOwner(teamId) {
    return props.teams.some((team) => team.id === teamId && team.is_owned === true);
}

function isOwnRow(member) {
    return props.actingUserId !== null && member.user_id === props.actingUserId;
}

/**
 * Mirrors the server rule: the team owner manages every row on their team, and
 * a member may remove only themself.
 */
function canManage(member) {
    return isTeamOwner(member.team_id) || isOwnRow(member);
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
    setParam(url, 'status', statusFilter.value);
    setParam(url, 'q', searchTerm.value);

    window.location.href = url.toString();
}

function upsertMember(member) {
    const index = members.value.findIndex((entry) => entry.id === member.id);

    members.value = index === -1
        ? [...members.value, member]
        : members.value.map((entry, position) => (position === index ? member : entry));

    statusDrafts[member.id] = member.status;
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
                team_id: form.team_id === '' ? null : Number(form.team_id),
                user_id: form.user_id === '' ? null : Number(form.user_id),
                status: form.status,
            }),
        });

        if (! await handleResponse(response)) {
            return;
        }

        const body = await response.json();

        upsertMember(body.member);
        cancelEdit();
    } finally {
        isSaving.value = false;
    }
}

async function updateStatus(member) {
    const status = statusDrafts[member.id];

    if (status === member.status) {
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
            body: JSON.stringify({ status }),
        });

        if (! await handleResponse(response)) {
            statusDrafts[member.id] = member.status;
            pageError.value = fieldError('status') || 'That membership state was not saved.';

            return;
        }

        const body = await response.json();

        upsertMember(body.member);
    } finally {
        savingId.value = null;
    }
}

async function deleteMember(member) {
    const label = member.user_name || 'this member';
    const question = isOwnRow(member) && ! isTeamOwner(member.team_id)
        ? 'Leave this team?'
        : `Remove ${label} from "${member.team_name || 'this team'}"?`;

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
            return;
        }

        members.value = members.value.filter((entry) => entry.id !== member.id);
        delete statusDrafts[member.id];
    } finally {
        deletingId.value = null;
    }
}
</script>

<template>
    <div class="team-members-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Team members</h1>
                    <p class="intro">
                        Put people on a team, move them between membership states, and remove them
                        when they leave.
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
            Adding a member needs a team you own and a second user account in this workspace.
        </p>

        <section class="filters" aria-label="Filters">
            <form class="filter-form" @submit.prevent="applyFilters">
                <div class="form-field">
                    <label for="member-team-filter">Team</label>
                    <select id="member-team-filter" v-model="teamFilter">
                        <option value="">All my teams</option>
                        <option v-for="team in teams" :key="team.id" :value="String(team.id)">
                            {{ team.name }}
                        </option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="member-status-filter">State</label>
                    <select id="member-status-filter" v-model="statusFilter">
                        <option value="">Any state</option>
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="member-search">Search</label>
                    <input
                        id="member-search"
                        v-model="searchTerm"
                        type="search"
                        placeholder="Member name, email, or team"
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
                    <label for="member-team">Team</label>
                    <select id="member-team" v-model="form.team_id" required>
                        <option value="" disabled>Choose a team you own</option>
                        <option v-for="team in ownedTeams" :key="team.id" :value="String(team.id)">
                            {{ team.name }}
                        </option>
                    </select>
                    <small v-if="fieldError('team_id')" class="field-error">{{ fieldError('team_id') }}</small>
                </div>

                <div class="form-field">
                    <label for="member-user">User</label>
                    <select id="member-user" v-model="form.user_id" required>
                        <option value="" disabled>Choose a user</option>
                        <option v-for="user in users" :key="user.id" :value="String(user.id)">
                            {{ user.name }} — {{ user.email }}
                        </option>
                    </select>
                    <small v-if="fieldError('user_id')" class="field-error">{{ fieldError('user_id') }}</small>
                </div>

                <div class="form-field">
                    <label for="member-status">Membership state</label>
                    <select id="member-status" v-model="form.status">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <small v-if="fieldError('status')" class="field-error">{{ fieldError('status') }}</small>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Adding…' : 'Add member' }}
                    </button>
                    <button class="button button-secondary" type="button" @click="cancelEdit">Done</button>
                </div>
            </form>
        </section>

        <section class="member-list" aria-label="Team members">
            <p class="list-summary">{{ memberTotal }} membership<span v-if="memberTotal !== 1">s</span> shown</p>

            <p v-if="groupedMembers.length === 0" class="notice notice-info">
                Nothing to show. Memberships of teams you own or belong to appear here.
            </p>

            <article v-for="group in groupedMembers" :key="group.team_id" class="team-group">
                <header class="team-group--header">
                    <h2>{{ group.team_name }}</h2>
                    <p class="team-group--meta">
                        {{ group.members.length }} member<span v-if="group.members.length !== 1">s</span>
                        <span v-if="isTeamOwner(group.team_id)" class="owner-tag">you own this team</span>
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
                            <span class="status-badge" :class="'status-' + member.status">
                                {{ statusLabel(member.status) }}
                            </span>
                            <span v-if="member.joined_at">Joined {{ formatDate(member.joined_at) }}</span>
                            <span v-else>Not yet active</span>
                        </p>
                    </div>

                    <div v-if="canManage(member)" class="member-actions">
                        <template v-if="isTeamOwner(member.team_id)">
                            <label class="visually-hidden" :for="'member-state-' + member.id">
                                Membership state for {{ member.user_name || 'member' }}
                            </label>
                            <select
                                :id="'member-state-' + member.id"
                                v-model="statusDrafts[member.id]"
                                :disabled="savingId === member.id"
                            >
                                <option v-for="status in statuses" :key="status" :value="status">
                                    {{ statusLabel(status) }}
                                </option>
                            </select>
                            <button
                                class="button button-secondary"
                                type="button"
                                :disabled="savingId === member.id || statusDrafts[member.id] === member.status"
                                @click="updateStatus(member)"
                            >
                                {{ savingId === member.id ? 'Saving…' : 'Save state' }}
                            </button>
                        </template>

                        <button
                            class="button button-danger"
                            type="button"
                            :disabled="deletingId === member.id"
                            @click="deleteMember(member)"
                        >
                            {{ deletingId === member.id ? 'Removing…' : (isOwnRow(member) && ! isTeamOwner(member.team_id) ? 'Leave team' : 'Remove') }}
                        </button>
                    </div>

                    <p v-else class="member-locked">Only the team owner can change this membership.</p>
                </div>
            </article>
        </section>
    </div>
</template>

<style scoped>
.team-members-page {
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
    background: #eef2f8;
    color: #2d4a7e;
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
    background: #fff4e5;
    color: #8a5300;
}

.status-suspended {
    background: #fdecec;
    color: #7f1d1d;
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
