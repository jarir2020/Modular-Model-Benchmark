<template>
    <div>
        <h1>Task Attachments</h1>
        <input type="file" @change="onFileChange" />
        <button @click="uploadFile">Upload</button>
        <ul>
            <li v-for="attachment in attachments" :key="attachment.id">
                {{ attachment.file_name }}
                <button @click="deleteFile(attachment.id)">Delete</button>
            </li>
        </ul>
    </div>
</template>

<script>
export default {
    data() {
        return {
            attachments: [],
            selectedFile: null
        };
    },
    methods: {
        onFileChange(event) {
            this.selectedFile = event.target.files[0];
        },
        uploadFile() {
            if (!this.selectedFile) return;
            const formData = new FormData();
            formData.append('file', this.selectedFile);
            formData.append('task_id', this.$route.params.taskId);
            this.$http.post('/attachments', formData)
                .then(response => {
                    this.attachments.push(response.data);
                    this.selectedFile = null;
                });
        },
        deleteFile(id) {
            this.$http.delete(`/attachments/${id}`)
                .then(() => {
                    this.attachments = this.attachments.filter(att => att.id !== id);
                });
        }
    },
    mounted() {
        // Fetch existing attachments
        this.$http.get(`/attachments?task_id=${this.$route.params.taskId}`)
            .then(response => {
                this.attachments = response.data;
            });
    }
};
</script>
