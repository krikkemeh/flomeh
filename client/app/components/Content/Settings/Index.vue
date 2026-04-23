<template>
  <main>
    <div class="wrap-content">

      <div class="navigation-tab no-select">
        <span :class="{active: activeTab == 'backup'}" @click="changeActiveTab('backup')">{{ lang('tab backup') }}</span>
        <span :class="{active: activeTab == 'user'}" @click="changeActiveTab('user')">{{ lang('tab user') }}</span>
        <span :class="{active: activeTab == 'options'}" @click="changeActiveTab('options')">{{ lang('tab options') }}</span>
        <span :class="{active: activeTab == 'refresh'}" @click="changeActiveTab('refresh')">{{ lang('refresh') }}</span>
        <span :class="{active: activeTab == 'reminders'}" @click="changeActiveTab('reminders')">{{ lang('reminders') }}</span>
        <span :class="{active: activeTab == 'sync-log'}" @click="changeActiveTab('sync-log')">Sync log</span>
        <span :class="{active: activeTab == 'error'}" @click="changeActiveTab('error')">Error</span>
      </div>

      <span class="loader fullsize-loader" v-if="loading"><i></i></span>

      <user v-if="activeTab == 'user'"></user>
      <options v-if="activeTab == 'options'"></options>
      <backup v-if="activeTab == 'backup'"></backup>
      <refresh v-if="activeTab == 'refresh'"></refresh>
      <reminders v-if="activeTab == 'reminders'"></reminders>
      <div class="settings-box sync-log-box" v-if="activeTab == 'sync-log'">
        <span class="loader fullsize-loader" v-if="syncLogLoading"><i></i></span>

        <div v-if=" ! syncLogLoading">
          <h2>Sync log</h2>
          <p v-if=" ! syncLogItems.length">No sync events yet.</p>

          <div class="sync-log-table-wrap" v-if="syncLogItems.length">
            <table class="sync-log-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Status</th>
                  <th>Series</th>
                  <th>Episode</th>
                  <th>Progress</th>
                  <th>Message</th>
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="item in syncLogItems"
                  :key="item.id"
                  :class="{'sync-log-row-actionable': !!item.series_title}"
                  @click="handleSyncLogRowClick(item)"
                >
                  <td>{{ item.id }}</td>
                  <td><span class="sync-status" :class="statusClass(item.status)">{{ item.status }}</span></td>
                  <td>{{ item.series_title || '-' }}</td>
                  <td>S{{ item.season_number || '?' }}E{{ item.episode_number || '?' }}</td>
                  <td>{{ item.progress !== null ? item.progress + '%' : '-' }}</td>
                  <td>{{ item.message || '-' }}</td>
                  <td>{{ item.created_at || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
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
        importLogUrl: '',
        syncLogLoading: false,
        syncLogItems: []
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

        if(tab === 'sync-log') {
          this.fetchSyncLog();
        }
      },

      setActiveTabFromRoute() {
        const availableTabs = ['backup', 'user', 'options', 'refresh', 'reminders', 'sync-log', 'error'];
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
      },

      fetchSyncLog() {
        this.syncLogLoading = true;

        fetch(`${config.api}/external/progress-events`, {credentials: 'same-origin'}).then(response => {
          return response.json();
        }).then(response => {
          this.syncLogItems = response.data || [];
          this.syncLogLoading = false;
        }).catch(() => {
          this.syncLogItems = [];
          this.syncLogLoading = false;
        });
      },

      handleSyncLogRowClick(item) {
        if( ! item.series_title) {
          return;
        }

        const existingOverride = item.override || {};
        const familyTitle = window.prompt(
          'Applica questa regola a tutti i titoli simili con questa radice',
          existingOverride.family_series_title || item.suggested_family_title || item.series_title
        );

        if(familyTitle === null) {
          return;
        }

        const tmdbId = window.prompt(`TMDb ID per "${item.series_title}"`, existingOverride.tmdb_id || item.tmdb_id || '');

        if(tmdbId === null) {
          return;
        }

        const forceSeasonDefault = existingOverride.force_season !== null && existingOverride.force_season !== undefined
          ? existingOverride.force_season
          : (item.season_number || '');
        const forceSeason = window.prompt('Use this season instead (vuoto = lascia originale)', forceSeasonDefault);

        if(forceSeason === null) {
          return;
        }

        const episodeShift = window.prompt(
          'Episode shift (es: 28 per far partire E1 da E29)',
          existingOverride.episode_shift !== undefined && existingOverride.episode_shift !== null ? existingOverride.episode_shift : '0'
        );

        if(episodeShift === null) {
          return;
        }

        fetch(`${config.api}/external/progress-events/${item.id}/override`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('#token').getAttribute('content')
          },
          body: JSON.stringify({
            family_title: familyTitle,
            tmdb_id: Number(tmdbId),
            force_season: forceSeason === '' ? null : Number(forceSeason),
            episode_shift: Number(episodeShift || 0)
          })
        }).then(response => {
          return response.json().then(data => ({ok: response.ok, data}));
        }).then(result => {
          if(!result.ok || !result.data.ok) {
            throw new Error(result.data.message || 'Could not save override.');
          }

          const reappliedCount = result.data.reappliedCount || 0;
          const message = result.data.message || 'Override saved.';
          alert(reappliedCount ? `${message} Riapplicata a ${reappliedCount} eventi simili.` : message);
          this.fetchSyncLog();
        }).catch(error => {
          alert(error.message || 'Could not save override.');
        });
      },

      statusClass(status) {
        return status ? `status-${status}` : '';
      }
    },

    watch: {
      $route() {
        this.setActiveTabFromRoute();
      }
    }
  }
</script>
