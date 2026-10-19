<template>
  <div class="invitations">
    <h1>Invitations</h1>
    <ul>
      <li v-for="inv in invitations" :key="inv.id">
        {{ inv.email }} - {{ inv.team_name }} - {{ inv.is_valid ? 'valid' : 'used/expired' }}
        <button @click="revoke(inv)" v-if="inv.is_valid">Revoke</button>
        <button @click="remove(inv)">Delete</button>
      </li>
    </ul>
    <form @submit.prevent="create">
      <select v-model="form.team_id"><option v-for="t in teams" :value="t.id">{{ t.name }}</option></select>
      <input v-model="form.email" placeholder="email" />
      <button type="submit">Invite</button>
    </form>
  </div>
</template>
<script setup>
import { ref } from 'vue';
const props = defineProps({ pageProps: Object });
const invitations = ref(props.pageProps.initialInvitations || []);
const teams = ref(props.pageProps.teams || []);
const form = ref({ team_id: null, email: '', expires_in_days: 7 });
const csrf = props.pageProps.csrfToken;
const routes = props.pageProps.routes;
async function create() {
  const res = await fetch(routes.store, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(form.value) });
  if (res.ok) { const d = await res.json(); invitations.value.unshift(d.invitation); }
}
async function revoke(inv) {
  const url = routes.revoke.replace('__ID__', inv.id);
  const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf } });
  if (res.ok) { const d = await res.json(); const i = invitations.value.findIndex(x=>x.id===inv.id); invitations.value[i]=d.invitation; }
}
async function remove(inv) {
  const url = routes.destroy.replace('__ID__', inv.id);
  const res = await fetch(url, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf } });
  if (res.ok) invitations.value = invitations.value.filter(x=>x.id!==inv.id);
}
</script>