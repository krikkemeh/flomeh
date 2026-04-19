<template>
  <main>
    <div class="wrap-content">

      <div class="navigation-tab no-select">
        <span :class="{active: activeTab == 'backup'}" @click="changeActiveTab('backup')">{{ lang('tab backup') }}</span>
        <span :class="{active: activeTab == 'user'}" @click="changeActiveTab('user')">{{ lang('tab user') }}</span>
        <span :class="{active: activeTab == 'options'}" @click="changeActiveTab('options')">{{ lang('tab options') }}</span>
        <span :class="{active: activeTab == 'refresh'}" @click="changeActiveTab('refresh')">{{ lang('refresh') }}</span>
        <span :class="{active: activeTab == 'reminders'}" @click="changeActiveTab('reminders')">{{ lang('reminders') }}</span>
        <span :class="{active: activeTab == 'error'}" @click="changeActiveTab('error')">Error</span>
      </div>

      <span class="loader fullsize-loader" v-if="loading"><i></i></span>

      <user v-if="activeTab == 'user'"></user>
      <options v-if="activeTab == 'options'"></options>
      <backup v-if="activeTab == 'backup'"></backup>
      <refresh v-if="activeTab == 'refresh'"></refresh>
      <reminders v-if="activeTab == 'reminders'"></reminders>
      <div class="settings-box error-box" v-if="activeTab == 'error'">
        <span class="loader fullsize-loader" v-if="errorLoading"><i></i></span>

        <div v-if=" ! errorLoading">
          <h2>Error</h2>
          <p v-if=" ! importStatus.failed && ! importStatus.running">No active import error.</p>
          <p v-if="importStatus.message"><strong>Message:</strong> {{ importStatus.message }}</p>
          <p v-if="importStatus.current_title"><strong>Last item:</strong> {{ importStatus.current_title }}</p>
          <p v-if="importStatus.current && importStatus.total"><strong>Progress:</strong> {{ importStatus.current }} / {{ importStatus.total }}</p>
          <p v-if="importStatus.started_at"><strong>Started:</strong> {{ importStatus.started_at }}</p>
          <p v-if="importStatus.updated_at"><strong>Updated:</strong> {{ importStatus.updated_at }}</p>
          <p v-if="importStatus.finished_at"><strong>Finished:</strong> {{ importStatus.finished_at }}</p>
          <p v-if="importLogUrl"><strong>Log:</strong> <a :href="importLogUrl" target="_blank">open log</a></p>

          <button type="button" class="setting-btn" v-if="importStatus.failed" @click="clearImportError()">Clear error</button>
        </div>
      </div>

    </div>
  </main>
</template>

<script>
  import User from './User.vue';
  import Options from './Options.vue';
  import Backup from './Backup.vue';
  import Refresh from './Refresh.vue';
  import Reminders from './Reminders.vue';

  import { mapState, mapActions } from 'vuex';
  import MiscHelper from '../../../helpers/misc';

  export default {
    mixins: [MiscHelper],

    created() {
      this.setPageTitle(this.lang('settings'));
      this.setActiveTabFromRoute();
      this.fetchImportError();
    },

    components: {
      User, Options, Backup, Refresh, Reminders
    },

    data() {
      return {
        activeTab: 'backup',
        errorLoading: false,
        importStatus: {},
        importLogUrl: ''
      }
    },

    computed: {
      ...mapState({
        loading: state => state.loading
      })
    },

    methods: {
      ...mapActions([ 'setPageTitle' ]),

      changeActiveTab(tab) {
        this.activeTab = tab;

        if(tab === 'error') {
          this.fetchImportError();
        }
      },

      setActiveTabFromRoute() {
        const availableTabs = ['backup', 'user', 'options', 'refresh', 'reminders', 'error'];
        const requestedTab = this.$route.query.tab;

        if(availableTabs.includes(requestedTab)) {
          this.activeTab = requestedTab;
        }
      },

      fetchImportError() {
        this.errorLoading = true;

        fetch(`${config.api}/import-jobs/pending`, {credentials: 'same-origin'}).then(response => {
          return response.json();
        }).then(response => {
          this.importStatus = response.status || {};
          this.importLogUrl = response.log_url || '';
          this.errorLoading = false;
        }).catch(() => {
          this.importStatus = {failed: true, message: 'Could not load import status.'};
          this.errorLoading = false;
        });
      },

      clearImportError() {
        fetch(`${config.api}/import-jobs/clear-error`, {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('#token').getAttribute('content')
          }
        }).then(() => {
          this.fetchImportError();
        }).catch(() => {
          alert('Could not clear import error.');
        });
      }
    },

    watch: {
      $route() {
        this.setActiveTabFromRoute();
      }
    }
  }
</script>
