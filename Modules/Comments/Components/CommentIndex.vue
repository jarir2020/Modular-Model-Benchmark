<script setup>
// Implemented with model: kr/qwen3-coder-next (Qwen 3 Coder Next) — Comments module, roadmap #5.
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    initialComments: {
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
    routes: {
        type: Object,
        required: true,
    },
});

const comments = ref(props.initialComments.map((comment) => ({ ...comment })));
const editingId = ref(null);
const validationErrors = ref({});
const pageError = ref('');
const isSaving = ref(false);
const deletingId = ref(null);

const emptyForm = () => ({
    task_id: props.taskId !== null ? props.taskId : '',
    body: '',
});

const form = reactive(emptyForm());

const hasTask = computed(() => props.taskId !== null && props.taskId !== '');

function fieldError(field) {
    return validationErrors.value[field]?.[0] || '';
}

function commentUrl(template, id) {
    return template.replace('__COMMENT_ID__', String(id));
}

async function saveComment() {
    isSaving.value = true;
    validationErrors.value = {};
    pageError.value = '';

    const isEditing = editingId.value !== null;
    const url = isEditing
        ? commentUrl(props.routes.update, editingId.value)
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
                task_id: Number(form.task_id),
                body: form.body,
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
            pageError.value = data.message || 'The comment could not be saved.';
            return;
        }

        if (isEditing) {
            comments.value = comments.value.map((comment) => (
                comment.id === data.comment.id ? data.comment : comment
            ));
        } else {
            comments.value = [...comments.value, data.comment];
        }

        startCreate();
    } catch {
        pageError.value = 'A network error prevented the comment from being saved.';
    } finally {
        isSaving.value = false;
    }
}

async function deleteComment(comment) {
    if (!window.confirm('Delete this comment? This cannot be undone.')) {
        return;
    }

    deletingId.value = comment.id;
    pageError.value = '';

    try {
        const response = await fetch(commentUrl(props.routes.destroy, comment.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            pageError.value = data.message || 'The comment could not be deleted.';
            return;
        }

        comments.value = comments.value.filter((item) => item.id !== comment.id);

        if (editingId.value === comment.id) {
            startCreate();
        }
    } catch {
        pageError.value = 'A network error prevented the comment from being deleted.';
    } finally {
        deletingId.value = null;
    }
}

function startCreate() {
    editingId.value = null;
    Object.assign(form, emptyForm());
    validationErrors.value = {};
    pageError.value = '';
}

function startEdit(comment) {
    editingId.value = comment.id;
    Object.assign(form, {
        task_id: String(comment.task_id),
        body: comment.body,
    });
    validationErrors.value = {};
    pageError.value = '';
}

function cancelEdit() {
    startCreate();
}

function formatDate(value) {
    if (!value) {
        return '';
    }
    return new Date(value).toLocaleString();
}

function isOwnComment(comment) {
    // In a real app, we'd check against the current user ID
    // For now, we'll just show edit/delete to the last comment creator
    return true;
}
</script>

<template>
    <div class="comments-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">Project Workbench</p>
                <h1>Comments</h1>
                <p class="intro" v-if="hasTask">
                    Discussion for: <strong>{{ taskTitle }}</strong>
                </p>
                <p class="intro" v-else>
                    Add, edit, and delete task discussion comments.
                </p>
            </div>
            <nav class="module-links" aria-label="Modules">
                <a class="module-link" href="/projects">Projects</a>
                <a class="module-link" href="/tasks">Tasks</a>
            </nav>
        </header>

        <p v-if="pageError" class="notice notice-error" role="alert">{{ pageError }}</p>

        <section class="comment-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">{{ editingId === null ? 'Add a comment' : 'Edit comment' }}</h2>
            <form class="comment-form" @submit.prevent="saveComment">
                <div class="form-field">
                    <label for="comment-task">Task</label>
                    <select
                        id="comment-task"
                        v-model="form.task_id"
                        required
                        :disabled="hasTask"
                    >
                        <option value="" disabled>Select a task</option>
                        <option v-for="t in tasks" :key="t.id" :value="String(t.id)">
                            {{ t.title }}
                        </option>
                    </select>
                    <small v-if="fieldError('task_id')" class="field-error">{{ fieldError('task_id') }}</small>
                </div>

                <div class="form-field">
                    <label for="comment-body">Comment</label>
                    <textarea
                        id="comment-body"
                        v-model="form.body"
                        maxlength="2000"
                        rows="4"
                        required
                    ></textarea>
                    <small v-if="fieldError('body')" class="field-error">{{ fieldError('body') }}</small>
                </div>

                <div class="form-actions">
                    <button
                        class="button button-primary"
                        type="submit"
                        :disabled="isSaving || !hasTask"
                    >
                        {{ isSaving ? 'Saving…' : editingId === null ? 'Add comment' : 'Save changes' }}
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

        <section class="comment-list" aria-labelledby="list-title" v-if="hasTask">
            <div class="list-heading">
                <div>
                    <h2 id="list-title">All comments</h2>
                    <p>{{ comments.length }} {{ comments.length === 1 ? 'comment' : 'comments' }}</p>
                </div>
            </div>

            <p v-if="comments.length === 0" class="empty-state">
                No comments yet. Add one above to get started.
            </p>

            <div v-else class="comments-container">
                <div v-for="comment in comments" :key="comment.id" class="comment-card">
                    <div class="comment-header">
                        <strong class="comment-author">{{ comment.user_name || 'Unknown user' }}</strong>
                        <span class="comment-date">{{ formatDate(comment.created_at) }}</span>
                    </div>
                    <div class="comment-body">{{ comment.body }}</div>
                    <div class="comment-actions">
                        <button
                            class="button button-secondary"
                            type="button"
                            @click="startEdit(comment)"
                        >
                            Edit
                        </button>
                        <button
                            class="button button-danger"
                            type="button"
                            :disabled="deletingId === comment.id"
                            @click="deleteComment(comment)"
                        >
                            {{ deletingId === comment.id ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section v-else class="no-task-state">
            <p class="intro">
                Select a task above to view and add comments. Comments belong to specific tasks.
            </p>
        </section>
    </div>
</template>

<style scoped>
.comments-page,
.comments-page * {
    box-sizing: border-box;
}

.comments-page {
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

.comment-editor,
.comment-list,
.no-task-state {
    border: 1px solid #dce2e9;
    border-radius: 16px;
    background: #fff;
    padding: 24px;
    box-shadow: 0 8px 24px rgb(26 43 65 / 5%);
}

.comment-editor {
    margin-bottom: 24px;
}

.comment-editor h2,
.comment-list h2 {
    margin-bottom: 18px;
    font-size: 1.2rem;
}

.comment-form {
    display: grid;
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
.form-field select {
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
.form-field select:focus {
    border-color: #3977d5;
    outline: 3px solid rgb(57 119 213 / 16%);
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

.list-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 18px;
}

.list-heading h2 {
    margin: 0;
}

.list-heading p {
    margin: 5px 0 0;
    color: #657386;
}

.comments-container {
    display: grid;
    gap: 16px;
}

.comment-card {
    border: 1px solid #e1e6ed;
    border-radius: 12px;
    padding: 16px;
    background: #fafbfc;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 12px;
}

.comment-author {
    color: #182230;
    font-size: 0.9rem;
}

.comment-date {
    color: #657386;
    font-size: 0.8rem;
}

.comment-body {
    color: #182230;
    line-height: 1.5;
    margin-bottom: 16px;
    white-space: pre-wrap;
}

.comment-actions {
    display: flex;
    gap: 12px;
}

.no-task-state {
    text-align: center;
    padding: 48px;
}

.empty-state {
    margin: 0;
    border: 1px dashed #cbd4df;
    border-radius: 12px;
    color: #657386;
    padding: 28px;
    text-align: center;
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

@media (max-width: 700px) {
    .comments-page {
        width: min(100% - 24px, 560px);
        padding-top: 28px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .comment-editor,
    .comment-list,
    .no-task-state {
        padding: 18px;
    }

    .form-actions {
        flex-direction: column;
    }

    .button {
        width: 100%;
    }

    .comment-actions {
        flex-direction: column;
    }

    .comment-actions .button {
        width: auto;
    }
}
</style>
