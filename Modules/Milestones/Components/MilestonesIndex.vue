<script setup>
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialMilestones: {
        type: Array,
        required: true,
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

const milestones = ref(props.initialMilestones.map((m) => ({ ...m })));
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

const visibleMilestones = computed(() => {
    const query = search.value.trim().toLowerCase();
    return milestones.value.filter((milestone) => {
        const matchesSearch = query === ''
            || milestone.name.toLowerCase().includes(query)
            || (milestone.description || '').toLowerCase().includes(query);
        const matchesStatus = statusFilter.value === 'all'
            || milestone.status === statusFilter.value;
        return matchesSearch && matchesStatus;
    });
});

function startCreate() {
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(milestone) {
    editingId.value = milestone.id;
    Object.assign(form, {
        name: milestone.name,
        description: milestone.description || '',
        status: milestone.status,
        due_date: milestone.due_date || '',
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

function milestoneUrl(template, id) {
    return template.replace('__MILESTONE_ID__', String(id));
}

async function saveMilestone() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    const isEditing = editingId.value !== null;
    const url = isEditing ? milestoneUrl(props.routes.update, editingId.value) : milestoneUrl(props.routes.store);

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
            pageError.value = data.message || 'The milestone could not be saved.';
            return;
        }

        if (isEditing) {
            milestones.value = milestones.value.map((m) =>
                m.id === data.milestone.id ? data.milestone : m
            );
        } else {
            milestones.value.unshift(data.milestone);
        }

        startCreate();
    } catch {
        pageError.value = 'A network error prevented the milestone from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteMilestone(milestone) {
    if (!window.confirm('Delete “' + milestone.name + '”? This cannot be undone.')) {
        return;
    }

    deletingId.value = milestone.id;
    pageError.value = '';

    try {
        const response = await fetch(
            milestoneUrl(props.routes.destroy, milestone.id),
            {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': props.csrfToken,
                },
            }
        );

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The milestone could not be deleted.';
            return;
        }

        milestones.value = milestones.value.filter((m) => m.id !== milestone.id);

        if (editingId.value === milestone.id) {
            startCreate();
        }
    } catch {
        pageError.value = 'A network error prevented the milestone from being deleted.';
    } finally {
        deletingId.value = null;
    }
}

function statusLabel(status) {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function formatDate(date) {
    if (!date) {
        return 'No due date';
    }
    return new Date(date).toLocaleDateString();
}
</script>

<template>
    <div class="milestones-page">
        <header class="page-header">
            <div class="page-header--wrapper">
                <div class="page-header--header">
                    <p class="eyebrow">Project Workbench</p>
                    <h1>Milestones</h1>
                    <p class="intro">Track named goals and due dates within a project.</p>
                </div>
                <button class="button button-primary" type="button" @click="startCreate">
                    New milestone
                </button>
            </div>
        </header>

        <section class="milestone-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'Create a milestone' : 'Edit milestone' }}</h2>
            <form class="milestone-form" @submit.prevent="saveMilestone">
                <div class="form-field">
                    <label for="milestone-name">Name</label>
                    <input id="milestone-name" v-model="form.name" maxlength="120" required type="text" autocomplete="off">
                    <small v-if="fieldError('name')" class="field-error">{{ fieldError('name') }}</small>
                </div>
                <div class="form-field">
                    <label for="milestone-description">Description</label>
                    <textarea id="milestone-description" v-model="form.description" maxlength="2000" rows="3"></textarea>
                    <small v-if="fieldError('description')" class="field-error">{{ fieldError('description') }}</small>
                </div>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="milestone-status">Status</label>
                        <select id="milestone-status" v-model="form.status" required>
                            <option value="planned">Planned</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                        <small v-if="fieldError('status')" class="field-error">{{ fieldError('status') }}</small>
                    </div>
                    <div class="form-field">
                        <label for="milestone-due-date">Due date</label>
                        <input id="milestone-due-date" v-model="form.due_date" type="date">
                        <small v-if="fieldError('due_date')" class="field-error">{{ fieldError('due_date') }}</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving…' : editingId === null ? 'Create milestone' : 'Save changes' }}
                    </button>
                    <button v-if="editingId !== null" class="button button-secondary" type="button" @click="cancelEdit">
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <section class="milestone-list" aria-labelledby="milestones-list-title">
            <div class="list-heading">
                <div>
                    <h2 id="milestones-list-title">All milestones</h2>
                    <p>{{ milestones.length }} milestone{{ milestones.length === 1 ? '' : 's' }}</p>
                </div>
                <div class="filters">
                    <label class="visually-hidden" for="milestone-search">Search milestones</label>
                    <input id="milestone-search" v-model="search" type="search" placeholder="Search milestones">
                    <label class="visually-hidden" for="milestone-status-filter">Filter by status</label>
                    <select id="milestone-status-filter" v-model="statusFilter">
                        <option value="all">All status</h2>
                        <option value="planned">Planned</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>

            <p v-if="visibleMilestones.length === 0" class="empty-state">
                {{ milestones.length === 0 ? 'No milestones yet. Create one above to get started.' : 'No milestones match these filters.' }}
            </p>

            <div v-else class="milestone-grid">
                <article v-for="milestone in visibleMilestones" :key="milestone.id" class="milestone-card">
                    <div class="card-heading">
                        <h3>{{ milestone.name }}</h3>
                        <span class="status-badge" :class="'status-' + milestone.status">
                            {{ statusLabel(milestone.status) }}
                        </span>
                    </div>
                    <p v-if="milestone.description" class="description">{{ milestone.description }}</p>
                    <p v-else class="description muted">No description</p>
                    <p class="due-date">Due: {{ formatDate(milestone.due_date) }}</p>
                    <div class="card-actions">
                        <button class="button button-secondary" type="button" @click="startEdit(milestone)">
                            Edit
                        </button>
                        <button
                            :disabled="deletingId === milestone.id"
                            class="button button-danger"
                            type="button"
                            @click="deleteMilestone(milestone)"
                        >
                            {{ deletingId === milestone.id ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>

<style scoped>
.milestones-page,
.milestones-page * {
    box-sizing: border-box;
}

.milestones-page {
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

.filters input,
.filters select {
    min-width: 0;
}

.filters input {
    min-width: 210px;
}

.filters select {
    min-width: 150px;
}

.milestone-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.milestone-card {
    min-width: 0;
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 18px;
    background: #fff;
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

.empty-state {
    margin: 0;
    border: 1px dashed #cbd4df;
    border-radius: 12px;
    color: #657386;
    padding: 28px;
    text-align: center;
}

@media (max-width: 700px) {
    .milestones-page {
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

    .milestone-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>