<template>
  <footer v-show=" ! loading && ! hideFooter">
    <div class="wrap">
      <span class="attribution">
        <a class="tmdb-logo" href="https://www.themoviedb.org/" target="_blank">
          <i class="icon-tmdb"></i>
        </a>
        This product uses the TMDb API but is not endorsed or certified by TMDb
      </span>

      <span class="footer-actions">
        <span :title="lang('change color')" class="icon-constrast"  @click="toggleColorScheme()"><i></i></span>
        <a class="icon-github" href="https://github.com/krikkemeh/flomeh" target="_blank"></a>
      </span>

      <div class="sub-links">
        <a v-if="auth" :href="settings" class="login-btn">{{ lang('settings') }}</a>
        <a v-if="auth" :href="logout" class="login-btn">{{ lang('logout') }}</a>
        <span v-if="auth && importStatusError" class="pending-jobs">
          Error: <a :href="importLogUrl" target="_blank">log</a>
        </span>
        <span v-else-if="auth && pendingImportJobs > 0" class="pending-jobs">Pending jobs...</span>
        <a v-if=" ! auth" :href="login" class="login-btn">Login</a>
      </div>
    </div>
  </footer>
</template>

<script>
  import { mapState, mapActions } from 'vuex';
  import MiscHelper from '../helpers/misc';
  import http from 'axios';

  export default {
    mixins: [MiscHelper],

    data() {
      return {
        hideFooter: false,
        auth: config.auth,
        logout: config.api + '/logout',
        login: config.url + '/login',
        settings: config.url + '/settings',
        pendingImportJobs: 0,
        importStatusError: false,
        importLogUrl: '',
        pendingJobsTimer: null
      }
    },

    computed: {
      ...mapState({
        colorScheme: state => state.colorScheme,
        loading: state => state.loading
      })
    },
    
    created() {
      this.disableFooter();
      this.fetchPendingJobs();

      if(this.auth) {
        this.pendingJobsTimer = setInterval(this.fetchPendingJobs, 5000);
      }
    },

    destroyed() {
      if(this.pendingJobsTimer) {
        clearInterval(this.pendingJobsTimer);
      }
    },

    methods: {
      ...mapActions([ 'setColorScheme' ]),

      toggleColorScheme() {
        const color = this.colorScheme === 'light' ? 'dark' : 'light';

        this.setColorScheme(color);
      },
      
      disableFooter() {
        this.hideFooter = this.$route.name === 'calendar';
      },

      fetchPendingJobs() {
        if( ! this.auth) {
          return;
        }

        http(`${config.api}/import-jobs/pending`).then(response => {
          const status = response.data.status || {};

          this.pendingImportJobs = response.data.pending || 0;
          this.importStatusError = !! status.failed;
          this.importLogUrl = response.data.log_url || '';
        });
      }
    },

    watch: {
      $route() {
        this.disableFooter();
      }
    }
  }
</script>
